<?php

namespace Tests\Feature;

use App\Exceptions\HBX\HbxApiException;
use App\Models\HbxApiLog;
use App\Services\HBX\HbxContentHotelService;
use App\Services\HBX\HbxSignatureGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HbxContentHotelTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function hotel_details_uses_the_content_api_and_logs_the_raw_body_without_credentials(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 10:00:00'));
        $payload = $this->contentPayload();
        $signature = (new HbxSignatureGenerator)->generate('test-api-key', 'test-secret', Carbon::now()->getTimestamp());

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($payload, 200),
        ]);

        $result = app(HbxContentHotelService::class)->getHotel(712, 'ENG');

        Http::assertSent(function ($request) use ($signature): bool {
            $url = $request->url();

            return $request->method() === 'GET'
                && str_contains($url, '/hotel-content-api/1.0/hotels/712/details')
                && str_contains($url, 'language=ENG')
                && str_contains($url, 'useSecondaryLanguage=false')
                && ! str_contains($url, '/hotel-api/1.0/hotels')
                && $request->hasHeader('Api-key', 'test-api-key')
                && $request->hasHeader('X-Signature', $signature);
        });

        $this->assertSame('content_hotel_detail', $result->operation);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame($payload, $result->rawBody);
        $this->assertSame(712, $result->data['hotel']['code']);
        $this->assertSame('Alua Suites las Rocas', $result->data['hotel']['name']['content']);
        $this->assertSame('25', $result->processTime);

        $log = HbxApiLog::query()->where('operation', 'content_hotel_detail')->firstOrFail();
        $this->assertSame('GET', $log->request_method);
        $this->assertStringContainsString('/hotel-content-api/1.0/hotels/712/details', $log->endpoint);
        $this->assertSame(200, $log->http_status);
        $this->assertTrue($log->successful);
        $this->assertNotNull($log->local_duration_ms);
        $this->assertSame('25', $log->supplier_process_time);
        $this->assertStringContainsString('Alua Suites las Rocas', (string) $log->response_payload);
        $this->assertStringContainsString('language', (string) $log->request_payload);
        $this->assertStringContainsString('ENG', (string) $log->request_payload);
        $this->assertStringNotContainsString('test-api-key', (string) $log->request_payload.(string) $log->response_payload);
        $this->assertStringNotContainsString('test-secret', (string) $log->request_payload.(string) $log->response_payload);
        $this->assertStringNotContainsString($signature, (string) $log->request_payload.(string) $log->response_payload);
        $this->assertContentTablesAreAbsent();
    }

    #[Test]
    public function the_discovery_command_prints_the_content_response(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($this->contentPayload(), 200),
        ]);

        $this->artisan('hbx:content:hotel', ['hotelCode' => 712, '--language' => 'ENG'])
            ->expectsOutputToContain('HTTP status: 200')
            ->expectsOutputToContain('Local duration ms:')
            ->expectsOutputToContain('HBX processTime: 25')
            ->expectsOutputToContain('HBX timestamp: 2026-09-29 10:00:00.000')
            ->expectsOutputToContain('Content hotel code: 712')
            ->expectsOutputToContain('Content hotel name: Alua Suites las Rocas')
            ->expectsOutputToContain('Alua Suites las Rocas')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_supplier_content_error_uses_the_existing_hbx_exception(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response('{"error":{"code":"INVALID_DATA","message":"Hotel not found."}}', 400),
        ]);

        try {
            app(HbxContentHotelService::class)->getHotel(712);
            $this->fail('A supplier error should raise the existing HBX exception.');
        } catch (HbxApiException $exception) {
            $this->assertSame('INVALID_DATA', $exception->supplierCode);
            $this->assertSame(400, $exception->httpStatus);
            $this->assertSame('content_hotel_detail', $exception->operation);
        }

        $this->assertContentTablesAreAbsent();
    }

    private function contentPayload(): string
    {
        return json_encode([
            'auditData' => [
                'processTime' => '25',
                'timestamp' => '2026-09-29 10:00:00.000',
            ],
            'hotel' => [
                'code' => 712,
                'name' => ['content' => 'Alua Suites las Rocas'],
                'description' => ['content' => 'Discovery fixture only.'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function assertContentTablesAreAbsent(): void
    {
        foreach ([
            'hotels',
            'hotel_contents',
            'hotel_images',
            'hotel_facilities',
            'hotel_rooms',
            'hotel_descriptions',
            'destinations',
            'countries',
            'boards',
            'categories',
            'chains',
            'segments',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table.' must not be created during content discovery.');
        }
    }
}
