<?php

namespace Tests\Feature;

use App\Exceptions\HBX\HbxAmbiguousResultException;
use App\Models\ContentHotel;
use App\Models\ContentSyncRun;
use App\Services\HBX\HbxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HbxContentSyncResumeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_checkpoint_advances_only_after_a_completed_page(): void
    {
        $checkpointAtSecondRequest = null;
        Http::fake(function (Request $request) use (&$checkpointAtSecondRequest) {
            $query = $this->pageQuery($request);

            if ($query['from'] === '3') {
                $checkpointAtSecondRequest = ContentSyncRun::query()->firstOrFail()->next_from;
            }

            $from = (int) $query['from'];

            return Http::response($this->page([
                $this->hotel($from),
                $this->hotel($from + 1),
            ], $from, $from + 1, 10), 200);
        });

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 2, '--limit' => 4])
            ->expectsOutputToContain('Status: stopped')
            ->assertSuccessful();

        $run = ContentSyncRun::query()->firstOrFail();
        $this->assertSame(3, $checkpointAtSecondRequest);
        $this->assertSame(5, $run->next_from);
        $this->assertSame(4, $run->fetched);
        $this->assertSame(ContentSyncRun::STOPPED, $run->status);
        $this->assertSame(ContentSyncRun::FULL, $run->sync_type);
    }

    #[Test]
    public function a_failed_http_page_does_not_advance_the_checkpoint(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::sequence()
                ->push($this->page([$this->hotel(5)], 1, 1, 10), 200)
                ->push(['error' => ['code' => 'HTTP_500', 'message' => 'down']], 500)
                ->push(['error' => ['code' => 'HTTP_500', 'message' => 'down']], 500)
                ->push(['error' => ['code' => 'HTTP_500', 'message' => 'down']], 500),
        ]);

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 4])
            ->assertFailed();

        $run = ContentSyncRun::query()->firstOrFail();
        $this->assertSame(ContentSyncRun::FAILED, $run->status);
        $this->assertSame(2, $run->next_from);
        $this->assertSame(1, $run->fetched);
        Http::assertSentCount(4);
    }

    #[Test]
    public function resume_starts_from_the_saved_supplier_position(): void
    {
        $calls = [];
        $resume = false;
        Http::fake(function (Request $request) use (&$calls, &$resume) {
            $query = $this->pageQuery($request);
            $from = (int) $query['from'];

            if (! $resume) {
                return Http::response($this->page([$this->hotel(1)], 1, 1, 10), 200);
            }

            $calls[] = $query['from'].'-'.$query['to'];

            return Http::response($this->page([$this->hotel($from + 10)], $from, $from, 10), 200);
        });
        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 1])->assertSuccessful();
        $resume = true;

        $this->artisan('hbx:content:sync-hotels', ['--resume' => true, '--limit' => 1])
            ->expectsOutputToContain('Resume: yes')
            ->expectsOutputToContain('From: 2')
            ->assertSuccessful();

        $this->assertSame(['2-2'], $calls);
        $this->assertSame(3, ContentSyncRun::query()->firstOrFail()->next_from);
        $this->assertSame(2, ContentHotel::query()->count());
    }

    #[Test]
    public function replaying_a_page_is_idempotent(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($this->page([$this->hotel(9)], 1, 1, 10), 200),
        ]);
        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 1])->assertSuccessful();

        $hotelId = ContentHotel::query()->where('hbx_hotel_code', 9)->value('id');
        $run = ContentSyncRun::query()->firstOrFail();
        $run->forceFill([
            'status' => ContentSyncRun::STOPPED,
            'next_from' => 1,
        ])->save();
        $this->artisan('hbx:content:sync-hotels', ['--resume' => true, '--limit' => 1])
            ->expectsOutputToContain('Unchanged: 1')
            ->assertSuccessful();

        $this->assertSame(1, ContentHotel::query()->count());
        $this->assertSame($hotelId, ContentHotel::query()->where('hbx_hotel_code', 9)->value('id'));
    }

    #[Test]
    public function a_completed_run_cannot_be_resumed(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($this->page([$this->hotel(4), $this->hotel(5)], 1, 2, 2), 200),
        ]);
        $this->artisan('hbx:content:sync-hotels', ['--batch' => 2])
            ->expectsOutputToContain('Status: completed')
            ->assertSuccessful();

        Http::fake();
        $this->artisan('hbx:content:sync-hotels', ['--resume' => true])
            ->expectsOutputToContain('cannot be resumed')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(ContentSyncRun::COMPLETED, ContentSyncRun::query()->firstOrFail()->status);
    }

    #[Test]
    public function full_and_differential_runs_resume_separately(): void
    {
        Http::fake(function (Request $request) {
            $query = $this->pageQuery($request);
            $from = (int) $query['from'];
            $differential = str_contains($request->url(), 'lastUpdateTime=2026-09-28');
            $code = $differential ? 30 + $from : ($from === 1 ? 1 : 20 + $from);

            return Http::response($this->page([$this->hotel($code)], $from, $from, 10), 200);
        });
        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 1])->assertSuccessful();
        $this->artisan('hbx:content:sync-hotels', [
            '--batch' => 1,
            '--limit' => 1,
            '--last-update' => '2026-09-28',
        ])->assertSuccessful();

        $full = ContentSyncRun::query()->where('sync_type', ContentSyncRun::FULL)->firstOrFail();
        $differential = ContentSyncRun::query()->where('sync_type', ContentSyncRun::DIFFERENTIAL)->firstOrFail();
        $this->assertSame(2, $full->next_from);
        $this->assertSame(2, $differential->next_from);

        $this->artisan('hbx:content:sync-hotels', ['--resume' => true, '--limit' => 1])->assertSuccessful();

        $this->assertSame(3, $full->refresh()->next_from);
        $this->assertSame(2, $differential->refresh()->next_from);
        $this->artisan('hbx:content:sync-hotels', [
            '--resume' => true,
            '--limit' => 1,
            '--last-update' => '2026-09-28',
        ])->assertSuccessful();

        $this->assertSame(3, $full->refresh()->next_from);
        $this->assertSame(3, $differential->refresh()->next_from);
    }

    #[Test]
    public function a_stop_request_ends_the_run_after_the_current_page(): void
    {
        Http::fake(function (Request $request) {
            $run = ContentSyncRun::query()->first();

            if ($run !== null) {
                $run->forceFill(['stop_requested' => true])->save();
            }

            $from = (int) $this->pageQuery($request)['from'];

            return Http::response($this->page([$this->hotel($from)], $from, $from, 10), 200);
        });

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 4])
            ->expectsOutputToContain('Status: stopped')
            ->assertSuccessful();

        $this->assertSame(1, ContentHotel::query()->count());
        $this->assertSame(2, ContentSyncRun::query()->firstOrFail()->next_from);
        Http::assertSentCount(1);
    }

    #[Test]
    public function sync_status_and_sync_stop_report_the_saved_run(): void
    {
        $run = ContentSyncRun::query()->create([
            'sync_type' => ContentSyncRun::FULL,
            'language' => 'ENG',
            'batch_size' => 50,
            'next_from' => 51,
            'fetched' => 50,
            'status' => ContentSyncRun::RUNNING,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);

        $this->artisan('hbx:content:sync-status')
            ->expectsOutputToContain('Run: '.$run->id)
            ->expectsOutputToContain('Next from: 51')
            ->expectsOutputToContain('Status: running')
            ->assertSuccessful();

        $this->artisan('hbx:content:sync-stop')
            ->expectsOutputToContain('Stop requested for run '.$run->id)
            ->assertSuccessful();

        $this->assertTrue($run->refresh()->stop_requested);
    }

    #[Test]
    public function content_get_retries_transient_failures_and_booking_writes_do_not(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::sequence()
                ->push(['error' => ['code' => 'HTTP_429', 'message' => 'slow']], 429)
                ->push($this->page([$this->hotel(3)], 1, 1, 1), 200),
        ]);

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 1])
            ->expectsOutputToContain('Imported: 1')
            ->assertSuccessful();
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_content_connection_failure_is_retried_and_a_booking_write_is_not(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new ConnectionException('reset');
            }

            return Http::response($this->page([$this->hotel(3)], 1, 1, 1), 200);
        });

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 1])
            ->expectsOutputToContain('Imported: 1')
            ->assertSuccessful();
        $this->assertSame(2, $attempts);

        $bookingAttempts = 0;
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function () use (&$bookingAttempts) {
            $bookingAttempts++;

            return Http::response(['error' => ['code' => 'PRODUCT_ERROR', 'message' => 'no']], 500);
        });

        try {
            app(HbxClient::class)->post('/hotel-api/1.0/bookings', ['holder' => ['name' => 'A', 'surname' => 'B']], 'booking');
            $this->fail('The booking write should fail once.');
        } catch (\Throwable $exception) {
            $this->assertSame(1, $bookingAttempts);
            $this->assertNotInstanceOf(HbxAmbiguousResultException::class, $exception);
        }

        $connectionAttempts = 0;
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function () use (&$connectionAttempts) {
            $connectionAttempts++;

            throw new ConnectionException('reset');
        });

        try {
            app(HbxClient::class)->post('/hotel-api/1.0/bookings', ['holder' => ['name' => 'A', 'surname' => 'B']], 'booking');
            $this->fail('The booking connection failure should not be retried.');
        } catch (HbxAmbiguousResultException) {
            $this->assertSame(1, $connectionAttempts);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function hotel(int $code, array $extra = []): array
    {
        return array_merge([
            'code' => $code,
            'name' => ['content' => 'Hotel '.$code],
            'countryCode' => 'ES',
        ], $extra);
    }

    /**
     * @param  list<array<string, mixed>>  $hotels
     * @return array<string, mixed>
     */
    private function page(array $hotels, int $from, int $to, int $total): array
    {
        return [
            'version' => '1.0',
            'from' => $from,
            'to' => $to,
            'total' => $total,
            'auditData' => ['processTime' => '12', 'timestamp' => '2026-09-29T10:00:00.000Z'],
            'hotels' => $hotels,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pageQuery(Request $request): array
    {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }
}
