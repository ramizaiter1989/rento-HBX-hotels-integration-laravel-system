<?php

namespace Tests\Feature;

use App\Exceptions\HBX\HbxValidationException;
use App\Models\ContentHotel;
use App\Models\HbxApiLog;
use App\Services\HBX\HbxContentHotelsService;
use App\Services\HBX\HbxSignatureGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HbxContentHotelsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_hotels_page_uses_the_content_list_endpoint_and_does_not_import(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 16:00:00'));
        $payload = $this->pagePayload();
        $signature = (new HbxSignatureGenerator)->generate('test-api-key', 'test-secret', Carbon::now()->getTimestamp());

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($payload, 200),
        ]);

        $result = app(HbxContentHotelsService::class)->page(1, 10, 'ENG');

        Http::assertSent(function ($request) use ($signature): bool {
            $url = $request->url();

            return $request->method() === 'GET'
                && str_contains($url, '/hotel-content-api/1.0/hotels?')
                && ! str_contains($url, '/hotels/712/details')
                && ! str_contains($url, '/hotel-api/1.0/hotels')
                && str_contains($url, 'fields=all')
                && str_contains($url, 'language=ENG')
                && str_contains($url, 'from=1')
                && str_contains($url, 'to=10')
                && str_contains($url, 'useSecondaryLanguage=false')
                && ! str_contains($url, 'useSecondaryLanguage=0')
                && ! str_contains($url, 'lastUpdateTime')
                && $request->hasHeader('Api-key', 'test-api-key')
                && $request->hasHeader('X-Signature', $signature);
        });

        $this->assertSame('content_hotels', $result->operation);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame('40', $result->processTime);

        $summary = app(HbxContentHotelsService::class)->summarize($result);
        $this->assertSame(2, $summary['hotelsReturned']);
        $this->assertSame('500', $summary['total']);
        $this->assertSame('1', $summary['pagination']['from']);
        $this->assertSame('10', $summary['pagination']['to']);
        $this->assertFalse($summary['hotel712']);
        $this->assertSame(['2026-09-01'], $summary['lastUpdateValues']);
        $this->assertSame([], $summary['lastUpdateTimeValues']);
        $this->assertContains('rooms', $summary['missingVsDetails']);
        $this->assertContains('listOnlyFlag', $summary['addedVsDetails']);
        $this->assertSame(0, ContentHotel::query()->count());

        $log = HbxApiLog::query()->where('operation', 'content_hotels')->firstOrFail();
        $this->assertStringContainsString('fields=all', (string) $log->endpoint);
        $this->assertStringNotContainsString('test-api-key', (string) $log->request_payload.(string) $log->response_payload);
        $this->assertStringNotContainsString('test-secret', (string) $log->request_payload.(string) $log->response_payload);
        $this->assertStringNotContainsString($signature, (string) $log->request_payload.(string) $log->response_payload);
    }

    #[Test]
    public function the_command_prints_the_page_summary_without_storing_hotels(): void
    {
        $payload = $this->pagePayload(includeHotel712: true);

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($payload, 200),
        ]);

        $this->artisan('hbx:content:hotels', ['--from' => 1, '--to' => 10, '--language' => 'ENG'])
            ->expectsOutputToContain('HTTP status: 200')
            ->expectsOutputToContain('HBX processTime: 40')
            ->expectsOutputToContain('Response bytes: '.strlen($payload))
            ->expectsOutputToContain('Hotels returned: 3')
            ->expectsOutputToContain('Pagination: from=1, to=10, total=500')
            ->expectsOutputToContain('Total: 500')
            ->expectsOutputToContain('Hotel 712 in range: yes')
            ->expectsOutputToContain('lastUpdateTime query: not sent')
            ->expectsOutputToContain('lastUpdate values: 2026-09-01, 2026-09-16')
            ->expectsOutputToContain('lastUpdateTime values: not present')
            ->expectsOutputToContain('Keys missing vs Hotel Details:')
            ->expectsOutputToContain('Keys added vs Hotel Details: listOnlyFlag')
            ->expectsOutputToContain('15 First Hotel')
            ->expectsOutputToContain('712 Alua Suites las Rocas')
            ->assertSuccessful();

        $this->assertSame(0, ContentHotel::query()->count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_page_larger_than_ten_hotels_is_rejected_before_any_request(): void
    {
        Http::fake();

        try {
            app(HbxContentHotelsService::class)->page(1, 11, 'ENG');
            $this->fail('A page above 10 hotels should be rejected.');
        } catch (HbxValidationException $exception) {
            $this->assertSame('INVALID_DATA', $exception->supplierCode);
            $this->assertSame('content_hotels', $exception->operation);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function last_update_is_sent_as_last_update_time_and_does_not_import(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($this->pagePayload(), 200),
        ]);

        $this->artisan('hbx:content:hotels', [
            '--from' => 1,
            '--to' => 10,
            '--language' => 'ENG',
            '--last-update' => '2026-09-28',
        ])
            ->expectsOutputToContain('lastUpdateTime query: 2026-09-28')
            ->expectsOutputToContain('HTTP status: 200')
            ->assertSuccessful();

        Http::assertSent(function ($request): bool {
            $url = $request->url();

            return str_contains($url, '/hotel-content-api/1.0/hotels?')
                && str_contains($url, 'fields=all')
                && str_contains($url, 'language=ENG')
                && str_contains($url, 'from=1')
                && str_contains($url, 'to=10')
                && str_contains($url, 'useSecondaryLanguage=false')
                && str_contains($url, 'lastUpdateTime=2026-09-28');
        });
        $this->assertSame(0, ContentHotel::query()->count());
    }

    #[Test]
    public function an_invalid_last_update_is_rejected_before_any_request(): void
    {
        Http::fake();

        foreach (['2026-9-28', '28-09-2026', '2026/09/28', '2026-02-31', '2026-09-28T00:00:00'] as $date) {
            try {
                app(HbxContentHotelsService::class)->page(1, 10, 'ENG', $date);
                $this->fail($date.' should be rejected.');
            } catch (HbxValidationException $exception) {
                $this->assertSame('INVALID_DATA', $exception->supplierCode);
            }
        }

        $this->artisan('hbx:content:hotels', ['--last-update' => '2026-02-31'])
            ->expectsOutputToContain('YYYY-MM-DD')
            ->assertFailed();

        Http::assertNothingSent();
    }

    private function pagePayload(bool $includeHotel712 = false): string
    {
        $hotels = [
            [
                'code' => 15,
                'name' => ['content' => 'First Hotel'],
                'lastUpdate' => '2026-09-01',
                'listOnlyFlag' => true,
            ],
            [
                'code' => 16,
                'name' => ['content' => 'Second Hotel'],
                'lastUpdate' => '2026-09-01',
            ],
        ];

        if ($includeHotel712) {
            $hotels[] = [
                'code' => 712,
                'name' => ['content' => 'Alua Suites las Rocas'],
                'lastUpdate' => '2026-09-16',
                'listOnlyFlag' => true,
            ];
        }

        return json_encode([
            'auditData' => [
                'processTime' => '40',
                'timestamp' => '2026-09-29 16:00:00.000',
            ],
            'from' => 1,
            'to' => 10,
            'total' => 500,
            'hotels' => $hotels,
        ], JSON_THROW_ON_ERROR);
    }
}
