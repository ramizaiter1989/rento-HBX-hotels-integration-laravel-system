<?php

namespace Tests\Feature;

use App\Models\ContentHotel;
use App\Models\ContentSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HbxContentSyncAbandonTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_actively_running_run_cannot_be_directly_abandoned(): void
    {
        $abandonExit = null;
        Http::fake(function () use (&$abandonExit) {
            $run = ContentSyncRun::query()->firstOrFail();
            $this->assertSame(ContentSyncRun::RUNNING, $run->status);
            $before = [
                'fetched' => $run->fetched,
                'next_from' => $run->next_from,
                'imported' => $run->imported,
                'http_requests' => $run->http_requests,
            ];

            $abandonExit = Artisan::call('hbx:content:sync-abandon', ['run' => (string) $run->id]);

            $run->refresh();
            $this->assertSame(ContentSyncRun::RUNNING, $run->status);
            $this->assertSame($before, [
                'fetched' => $run->fetched,
                'next_from' => $run->next_from,
                'imported' => $run->imported,
                'http_requests' => $run->http_requests,
            ]);

            $run->forceFill(['stop_requested' => true])->save();

            return Http::response([
                'from' => 1,
                'to' => 1,
                'total' => 10,
                'hotels' => [[
                    'code' => 1,
                    'name' => ['content' => 'Hotel 1'],
                    'countryCode' => 'ES',
                ]],
            ], 200);
        });

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 4])
            ->expectsOutputToContain('Status: stopped')
            ->assertSuccessful();

        $this->assertSame(1, $abandonExit);

        $run = ContentSyncRun::query()->firstOrFail();
        $this->assertSame(ContentSyncRun::STOPPED, $run->status);
        $this->assertSame(1, $run->fetched);
        $this->assertSame(2, $run->next_from);

        $this->artisan('hbx:content:sync-abandon', ['run' => (string) $run->id])
            ->expectsOutputToContain('Status: abandoned')
            ->assertSuccessful();
        $this->assertSame(ContentSyncRun::ABANDONED, $run->refresh()->status);
        $this->assertSame(1, $run->fetched);
        $this->assertSame(2, $run->next_from);
    }

    #[Test]
    public function a_running_run_tells_the_operator_to_stop_before_abandoning(): void
    {
        $run = $this->storedRun(ContentSyncRun::RUNNING, [
            'fetched' => 12,
            'next_from' => 13,
            'imported' => 4,
            'http_requests' => 2,
        ]);

        $this->artisan('hbx:content:sync-abandon', ['run' => (string) $run->id])
            ->expectsOutputToContain('is running and cannot be abandoned')
            ->expectsOutputToContain('php artisan hbx:content:sync-stop')
            ->expectsOutputToContain('php artisan hbx:content:sync-abandon '.$run->id)
            ->assertFailed();

        $run->refresh();
        $this->assertSame(ContentSyncRun::RUNNING, $run->status);
        $this->assertSame(12, $run->fetched);
        $this->assertSame(13, $run->next_from);
        $this->assertSame(4, $run->imported);
        $this->assertSame(2, $run->http_requests);
    }

    #[Test]
    public function a_failed_run_can_be_abandoned_without_losing_statistics_or_content(): void
    {
        $hotel = ContentHotel::query()->create(['hbx_hotel_code' => 712]);
        $run = $this->storedRun(ContentSyncRun::FAILED, [
            'fetched' => 0,
            'next_from' => 1,
            'requested_limit' => null,
            'imported' => 14,
            'unchanged' => 986,
            'http_requests' => 1,
            'supplier_total' => 305218,
            'stop_reason' => 'HTTP 403',
        ]);

        $this->artisan('hbx:content:sync-abandon', ['run' => (string) $run->id])
            ->expectsOutputToContain('Abandoned run '.$run->id)
            ->expectsOutputToContain('Status: abandoned')
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame(ContentSyncRun::ABANDONED, $run->status);
        $this->assertSame(0, $run->fetched);
        $this->assertSame(1, $run->next_from);
        $this->assertNull($run->requested_limit);
        $this->assertSame(14, $run->imported);
        $this->assertSame(986, $run->unchanged);
        $this->assertSame(1, $run->http_requests);
        $this->assertSame(305218, $run->supplier_total);
        $this->assertSame('HTTP 403', $run->stop_reason);
        $this->assertSame($hotel->id, ContentHotel::query()->where('hbx_hotel_code', 712)->value('id'));
        $this->assertSame(1, ContentSyncRun::query()->count());

        $this->artisan('hbx:content:sync-status', ['--id' => $run->id])
            ->expectsOutputToContain('Status: abandoned')
            ->expectsOutputToContain('Fetched total: 0')
            ->assertSuccessful();

        $this->get('/developer/hbx/sync')
            ->assertOk()
            ->assertSee('abandoned')
            ->assertSee('php artisan hbx:content:sync-abandon {run}')
            ->assertSee('wait until its status is stopped');

        Http::fake();
        $this->artisan('hbx:content:sync-hotels', ['--resume' => true])
            ->expectsOutputToContain('abandoned and cannot be resumed')
            ->assertFailed();
        Http::assertNothingSent();
        $this->assertSame(ContentSyncRun::ABANDONED, $run->refresh()->status);
    }

    #[Test]
    public function a_stopped_run_can_be_abandoned(): void
    {
        $run = $this->storedRun(ContentSyncRun::STOPPED, [
            'fetched' => 2500,
            'next_from' => 2501,
            'requested_limit' => 10000,
        ]);

        $this->artisan('hbx:content:sync-abandon', ['run' => (string) $run->id])->assertSuccessful();

        $run->refresh();
        $this->assertSame(ContentSyncRun::ABANDONED, $run->status);
        $this->assertSame(2500, $run->fetched);
        $this->assertSame(2501, $run->next_from);
        $this->assertSame(10000, $run->requested_limit);
    }

    #[Test]
    public function a_completed_run_cannot_be_abandoned(): void
    {
        $run = $this->storedRun(ContentSyncRun::COMPLETED, [
            'fetched' => 4,
            'next_from' => 5,
            'requested_limit' => 4,
        ]);

        $this->artisan('hbx:content:sync-abandon', ['run' => (string) $run->id])
            ->expectsOutputToContain('cannot be abandoned')
            ->assertFailed();

        $run->refresh();
        $this->assertSame(ContentSyncRun::COMPLETED, $run->status);
        $this->assertSame(4, $run->fetched);
        $this->assertSame(5, $run->next_from);
    }

    #[Test]
    public function a_later_limited_run_stays_independent_of_an_abandoned_run(): void
    {
        $legacy = $this->storedRun(ContentSyncRun::FAILED, [
            'fetched' => 0,
            'next_from' => 1,
            'requested_limit' => null,
        ]);
        $this->artisan('hbx:content:sync-abandon', ['run' => (string) $legacy->id])->assertSuccessful();

        $armStop = true;
        Http::fake(function (Request $request) use (&$armStop, $legacy) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $from = (int) ($query['from'] ?? 1);
            $current = ContentSyncRun::query()->whereKeyNot($legacy->id)->latest('id')->first();

            if ($armStop && $current !== null) {
                $current->forceFill(['stop_requested' => true])->save();
                $armStop = false;
            }

            return Http::response([
                'from' => $from,
                'to' => $from,
                'total' => 20,
                'hotels' => [[
                    'code' => $from,
                    'name' => ['content' => 'Hotel '.$from],
                    'countryCode' => 'ES',
                ]],
            ], 200);
        });

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 4])->assertSuccessful();

        $legacy->refresh();
        $current = ContentSyncRun::query()->whereKeyNot($legacy->id)->firstOrFail();
        $this->assertSame(ContentSyncRun::ABANDONED, $legacy->status);
        $this->assertSame(0, $legacy->fetched);
        $this->assertNull($legacy->requested_limit);
        $this->assertSame(4, $current->requested_limit);
        $this->assertSame(1, $current->fetched);
        $this->assertSame(ContentSyncRun::STOPPED, $current->status);

        $this->artisan('hbx:content:sync-hotels', ['--resume' => true])
            ->expectsOutputToContain('Run: '.$current->id)
            ->expectsOutputToContain('Remaining: 3')
            ->assertSuccessful();

        $legacy->refresh();
        $current->refresh();
        $this->assertSame(ContentSyncRun::ABANDONED, $legacy->status);
        $this->assertSame(0, $legacy->fetched);
        $this->assertSame(1, $legacy->next_from);
        $this->assertSame(4, $current->fetched);
        $this->assertSame(4, $current->requested_limit);
        $this->assertSame(ContentSyncRun::COMPLETED, $current->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function storedRun(string $status, array $overrides = []): ContentSyncRun
    {
        return ContentSyncRun::query()->create($overrides + [
            'sync_type' => ContentSyncRun::FULL,
            'language' => 'ENG',
            'batch_size' => 50,
            'next_from' => 1,
            'fetched' => 0,
            'status' => $status,
            'stop_reason' => null,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);
    }
}
