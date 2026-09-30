<?php

namespace Tests\Feature;

use App\Models\HotelBooking;
use App\Models\HbxApiLog;
use App\Models\RateSelection;
use App\Support\JsonDecimals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\HbxFixture;
use Tests\TestCase;

class HbxWorkflowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function status_request_signs_headers_and_never_sends_the_secret(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1700000000));

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response('{"status":"OK","auditData":{"processTime":"5","timestamp":"2026-09-27 12:00:00.000","secret":"test-secret"}}', 200),
        ]);

        $this->post(route('hbx.status.test'))
            ->assertRedirect();

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->hasHeader('Api-key', 'test-api-key')
                && $request->hasHeader('X-Signature', '5daa69896964d53bf783a29cc9671338124ff3b2ba8be1d6988adc21c3126c22')
                && $request->hasHeader('Accept', 'application/json')
                && ! $request->hasHeader('Secret')
                && ! str_contains(json_encode($request->headers()), 'test-secret');
        });

        $log = HbxApiLog::query()->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('test-secret', (string) $log->response_payload);
        $this->assertStringNotContainsString('test-api-key', (string) $log->request_payload);
        $this->assertStringNotContainsString('5daa69896964d53bf783a29cc9671338124ff3b2ba8be1d6988adc21c3126c22', (string) $log->request_payload);

        $this->get(route('hbx.status'))
            ->assertOk()
            ->assertSee('OK')
            ->assertDontSee('test-secret')
            ->assertDontSee('test-api-key');
    }

    #[Test]
    public function get_status_retries_once_after_a_connection_failure(): void
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new ConnectionException('timed out');
            }

            return Http::response('{"status":"OK","auditData":{"processTime":"5","timestamp":"t"}}', 200);
        });

        $this->post(route('hbx.status.test'))->assertRedirect();

        $this->assertSame(2, $attempts);
        $this->assertSame(1, HbxApiLog::query()->where('successful', true)->count());
    }

    #[Test]
    public function conflicting_search_filters_do_not_call_hbx(): void
    {
        $this->post(route('hotels.search.store'), $this->searchPayload([
            'destination_code' => 'PMI',
            'hotel_code' => '712',
        ]))->assertSessionHasErrors();

        Http::assertNothingSent();
    }

    #[Test]
    public function availability_maps_a_single_destination_filter(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response(HbxFixture::availability('RATEKEY-BOOKABLE'), 200),
        ]);

        $this->post(route('hotels.search.store'), $this->searchPayload())
            ->assertRedirect();

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && ($body['destination']['code'] ?? null) === 'PMI'
                && ! array_key_exists('hotels', $body)
                && ($body['occupancies'][0]['adults'] ?? null) === 2;
        });

        $this->assertDatabaseHas('hotel_searches', ['destination_code' => 'PMI', 'hotels_returned' => 1]);
    }

    #[Test]
    public function supplier_errors_are_shown_without_a_stack_trace(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response('{"error":{"code":"INVALID_DATA","message":"Check-in must be in the future."}}', 400),
        ]);

        $this->from(route('hotels.search'))
            ->post(route('hotels.search.store'), $this->searchPayload())
            ->assertRedirect(route('hotels.search'))
            ->assertSessionHas('hbx_error');

        $this->get(route('hotels.search'))
            ->assertSee('INVALID_DATA')
            ->assertSee('Check-in must be in the future.')
            ->assertDontSee('Stack trace');
    }

    #[Test]
    public function recheck_rates_cannot_be_booked_until_checkrate_replaces_the_key(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/hotel-api/1.0/hotels' => Http::response(HbxFixture::availability('RATEKEY-OLD', 'RECHECK'), 200),
            'https://api.test.hotelbeds.com/hotel-api/1.0/checkrates' => Http::response(HbxFixture::checkRate('RATEKEY-NEW'), 200),
            'https://api.test.hotelbeds.com/hotel-api/1.0/bookings' => Http::response(HbxFixture::booking(), 200),
        ]);

        $this->post(route('hotels.search.store'), $this->searchPayload())->assertRedirect();
        $searchId = \App\Models\HotelSearch::query()->value('id');

        $this->post(route('hotels.select', $searchId), ['rate_key' => 'RATEKEY-OLD'])
            ->assertRedirect(route('hotels.results', $searchId))
            ->assertSessionHas('hbx_error');

        $blocked = RateSelection::query()->first();
        $this->assertFalse($blocked->bookingAllowed());
        $this->get(route('bookings.create', ['selection' => $blocked->id]))
            ->assertRedirect(route('hotels.results', $searchId));

        $this->post(route('hotels.check-rate', $searchId), ['rate_key' => 'RATEKEY-OLD'])
            ->assertRedirect();

        $selection = RateSelection::query()->first();
        $this->assertSame('RATEKEY-OLD', $selection->original_rate_key);
        $this->assertSame('RATEKEY-NEW', $selection->rate_key);
        $this->assertNotNull($selection->checkrate_completed_at);
        $this->assertTrue($selection->bookingAllowed());

        $this->post(route('bookings.store'), $this->bookingPayload($selection->id))->assertRedirect();

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/checkrates')) {
                return false;
            }

            return ($request->data()['rooms'][0]['rateKey'] ?? null) === 'RATEKEY-OLD';
        });

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/bookings')) {
                return false;
            }

            $body = $request->data();

            $reference = $body['clientReference'] ?? '';

            return ($body['rooms'][0]['rateKey'] ?? null) === 'RATEKEY-NEW'
                && ($body['rooms'][0]['paxes'][0]['type'] ?? null) === 'AD'
                && ($body['rooms'][0]['paxes'][1]['type'] ?? null) === 'AD'
                && is_string($reference)
                && strlen($reference) >= 1
                && strlen($reference) <= 20
                && $reference === \App\Models\HotelBooking::query()->value('client_reference');
        });
    }

    #[Test]
    public function a_bookable_rate_can_be_booked_and_is_not_submitted_twice(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/hotel-api/1.0/hotels' => Http::response(HbxFixture::availability('RATEKEY-BOOKABLE'), 200),
            'https://api.test.hotelbeds.com/hotel-api/1.0/bookings' => Http::response(HbxFixture::booking('1-9000001'), 200),
        ]);

        $this->post(route('hotels.search.store'), $this->searchPayload());
        $searchId = \App\Models\HotelSearch::query()->value('id');
        $this->post(route('hotels.select', $searchId), ['rate_key' => 'RATEKEY-BOOKABLE']);
        $selection = RateSelection::query()->first();
        $payload = $this->bookingPayload($selection->id);

        $this->post(route('bookings.store'), $payload)->assertRedirect();
        $this->post(route('bookings.store'), $payload)->assertRedirect();

        $this->assertSame(1, HotelBooking::query()->count());
        Http::assertSentCount(2);

        $booking = HotelBooking::query()->with('rooms.taxes', 'rooms.cancellationPolicies')->first();
        $this->assertSame('CONFIRMED', $booking->status);
        $this->assertSame('1-9000001', $booking->hbx_reference);
        $this->assertSame('121.18', $booking->total_net);
        $this->assertSame('121.18', $booking->pending_amount);
        $this->assertFalse((bool) $booking->rooms->first()->taxes->first()->included);
        $this->assertSame('4.40', $booking->rooms->first()->taxes->first()->amount);
        $this->assertSame('118.13', $booking->rooms->first()->cancellationPolicies->first()->amount);
        $this->assertSame('2026-10-08T23:59:00+02:00', $booking->rooms->first()->cancellationPolicies->first()->raw_from_value);
        $this->assertNotSame('125.58', $booking->total_net);
    }

    #[Test]
    public function a_booking_timeout_is_ambiguous_and_is_not_retried(): void
    {
        $selection = $this->bookableSelection();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('timed out');
        });

        $payload = $this->bookingPayload($selection->id);
        $this->post(route('bookings.store'), $payload)->assertRedirect();
        $this->post(route('bookings.store'), $payload)->assertRedirect();

        $booking = HotelBooking::query()->first();
        $this->assertSame(1, $attempts);
        $this->assertSame(1, HotelBooking::query()->count());
        $this->assertSame('ambiguous', $booking->status);
        $this->assertNull($booking->hbx_reference);
        $this->assertLessThanOrEqual(20, strlen($booking->client_reference));
    }

    #[Test]
    public function a_failed_booking_attempt_is_kept_and_not_reused_as_a_success(): void
    {
        $selection = $this->bookableSelection();

        $this->replaceHttpFake([
            'https://api.test.hotelbeds.com/*' => Http::response('{"error":{"code":"INVALID_DATA","message":"Attribute size must be between 1 and 20."}}', 400),
        ]);

        $payload = $this->bookingPayload($selection->id, '33333333-3333-4333-8333-333333333333');
        $this->post(route('bookings.store'), $payload)->assertRedirect();

        $failed = HotelBooking::query()->first();
        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->hbx_reference);
        $original = $failed->only(['id', 'client_reference', 'status', 'holder_name', 'holder_surname']);

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response(HbxFixture::booking('1-9000099'), 200),
        ]);

        $this->post(route('bookings.store'), $payload)->assertRedirect();

        $failed->refresh();
        $this->assertSame($original, $failed->only(['id', 'client_reference', 'status', 'holder_name', 'holder_surname']));
        $this->assertSame(1, HotelBooking::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_colliding_client_reference_is_replaced_before_hbx_is_called(): void
    {
        $selection = $this->bookableSelection();

        HotelBooking::query()->create([
            'client_reference' => 'RENTO-260927-AAAAAAA',
            'submission_token' => '44444444-4444-4444-8444-444444444444',
            'status' => HotelBooking::STATUS_FAILED,
            'holder_name' => 'Old',
            'holder_surname' => 'Failure',
        ]);

        $this->app->instance(\App\Services\HBX\ClientReferenceGenerator::class, new class extends \App\Services\HBX\ClientReferenceGenerator
        {
            public int $calls = 0;

            public function generate(): string
            {
                $this->calls++;

                return $this->calls === 1 ? 'RENTO-260927-AAAAAAA' : 'RENTO-260927-BBBBBBB';
            }
        });

        $this->replaceHttpFake([
            'https://api.test.hotelbeds.com/*' => Http::response(HbxFixture::booking('1-9000088'), 200),
        ]);

        $this->post(route('bookings.store'), $this->bookingPayload($selection->id, '55555555-5555-4555-8555-555555555555'))
            ->assertRedirect();

        $historical = HotelBooking::query()->where('client_reference', 'RENTO-260927-AAAAAAA')->first();
        $created = HotelBooking::query()->where('status', 'CONFIRMED')->first();

        $this->assertSame('failed', $historical->status);
        $this->assertSame('Old', $historical->holder_name);
        $this->assertSame('RENTO-260927-BBBBBBB', $created->client_reference);

        Http::assertSent(function ($request) use ($created): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/bookings')
                && ($request->data()['clientReference'] ?? null) === $created->client_reference;
        });
    }

    #[Test]
    public function booking_detail_refresh_keeps_the_original_snapshot(): void
    {
        $booking = $this->confirmedBooking();
        $original = $booking->raw_booking_response;

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response(HbxFixture::detail('1-9000001', 'CONFIRMED', 'Changed'), 200),
        ]);

        $this->post(route('bookings.refresh', $booking))->assertRedirect();
        $booking->refresh();

        $this->assertSame($original, $booking->raw_booking_response);
        $this->assertSame('Changed', $booking->holder_surname);
        $this->assertStringContainsString('Changed', $booking->live_hbx_response);
    }

    #[Test]
    public function booking_list_marks_unknown_supplier_rows_and_does_not_import_them(): void
    {
        $booking = $this->confirmedBooking();

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response(json_encode([
                'auditData' => ['processTime' => '9', 'timestamp' => 't'],
                'bookings' => [
                    'bookings' => [
                        ['reference' => '1-9000001', 'clientReference' => $booking->client_reference, 'status' => 'CONFIRMED', 'holder' => ['name' => 'Booking', 'surname' => 'Test'], 'hotel' => ['name' => 'Test Hotel']],
                        ['reference' => '9-1', 'clientReference' => 'SOMEONE-ELSE', 'status' => 'CONFIRMED', 'holder' => ['name' => 'Other', 'surname' => 'Guest'], 'hotel' => ['name' => 'Other Hotel']],
                    ],
                    'total' => 2,
                ],
            ]), 200),
        ]);

        $this->get(route('bookings.hbx', [
            'fetch' => 1,
            'start' => now()->toDateString(),
            'end' => now()->toDateString(),
            'filter_type' => 'CREATION',
            'status' => 'ALL',
            'from' => 1,
            'to' => 25,
        ]))->assertOk()
            ->assertSee('Local booking')
            ->assertSee('Unknown supplier booking')
            ->assertSee('SOMEONE-ELSE');

        $this->assertSame(1, HotelBooking::query()->count());
    }

    #[Test]
    public function cancellation_simulation_does_not_change_the_booking(): void
    {
        $booking = $this->confirmedBooking();

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response('{"auditData":{"processTime":"7"},"booking":{"reference":"1-9000001","status":"CANCELLED","cancellationReference":"sim-ref","hotel":{"cancellationAmount":10.00,"currency":"EUR"}}}', 200),
        ]);

        $this->post(route('bookings.cancel-simulation', $booking))->assertRedirect();
        $booking->refresh();

        $this->assertSame('CONFIRMED', $booking->status);
        $this->assertNull($booking->cancellation_reference);
        $this->assertSame('CANCELLED', $booking->simulations()->value('simulated_status'));
        $this->assertSame('sim-ref', $booking->simulations()->value('supplier_cancellation_reference'));
    }

    #[Test]
    public function actual_cancellation_updates_the_booking_and_keeps_the_confirmation_snapshot(): void
    {
        $booking = $this->confirmedBooking();
        $original = $booking->raw_booking_response;

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response('{"auditData":{"processTime":"11"},"booking":{"reference":"1-9000001","status":"CANCELLED","cancellationReference":"a5013b70aaf38e7ab36e","hotel":{"cancellationAmount":0,"currency":"EUR"}}}', 200),
        ]);

        $this->post(route('bookings.cancel', $booking), ['confirm_cancel' => '1'])->assertRedirect();
        $booking->refresh();

        $this->assertSame('CANCELLED', $booking->status);
        $this->assertSame('a5013b70aaf38e7ab36e', $booking->cancellation_reference);
        $this->assertSame('0.00', $booking->cancellation_amount);
        $this->assertSame($original, $booking->raw_booking_response);
        $this->assertStringContainsString('CONFIRMED', $original);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), 'cancellationFlag=CANCELLATION');
        });
    }

    #[Test]
    public function modification_simulation_copies_the_live_booking_and_changes_only_the_holder(): void
    {
        $booking = $this->confirmedBooking();
        $originalConfirmation = $booking->raw_booking_response;
        $originalLive = $booking->live_hbx_response;
        $detailJson = $this->liveDetail();

        $this->replaceHttpFake(function ($request) use ($detailJson) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/bookings/1-9000001')) {
                return Http::response($detailJson, 200);
            }

            if ($request->method() === 'PUT' && str_contains($request->url(), '/bookings/1-9000001')) {
                return Http::response($this->modificationSimulationResponse(), 200);
            }

            return Http::response('{"error":{"code":"INVALID_DATA","message":"Unexpected request."}}', 500);
        });

        $this->from(route('bookings.show', $booking))
            ->post(route('bookings.modify-simulation', $booking), [
                'holder_name' => 'BOOKING',
                'holder_surname' => 'MODIFIED',
            ])
            ->assertRedirect(route('bookings.show', $booking));

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $this->assertSame('GET', $recorded[0][0]->method());
        $this->assertStringEndsWith('/bookings/1-9000001', $recorded[0][0]->url());
        $this->assertSame('PUT', $recorded[1][0]->method());

        $expectedBooking = JsonDecimals::decode($detailJson)['booking'];
        $expectedBooking['holder']['name'] = 'BOOKING';
        $expectedBooking['holder']['surname'] = 'MODIFIED';

        $body = $recorded[1][0]->data();
        $this->assertSame([
            'mode' => 'SIMULATION',
            'booking' => $expectedBooking,
        ], $body);
        $this->assertSame(712, $body['booking']['hotel']['code']);
        $this->assertSame($expectedBooking['hotel']['rooms'], $body['booking']['hotel']['rooms']);
        $this->assertSame('BOOKING', $body['booking']['holder']['name']);
        $this->assertSame('MODIFIED', $body['booking']['holder']['surname']);
        $this->assertArrayNotHasKey('executionMode', $body);

        $booking->refresh();
        $this->assertSame('CONFIRMED', $booking->status);
        $this->assertSame('Booking', $booking->holder_name);
        $this->assertSame('Test', $booking->holder_surname);
        $this->assertSame('712', (string) $booking->hotel_code);
        $this->assertSame('EUR', $booking->currency);
        $this->assertSame($originalConfirmation, $booking->raw_booking_response);
        $this->assertSame($originalLive, $booking->live_hbx_response);
        $this->assertSame('MODIFIED', $booking->simulations()->value('holder_surname'));
        $this->assertSame('CANCELLED', $booking->simulations()->value('simulated_status'));

        $this->get(route('bookings.show', $booking))
            ->assertSee('Current real booking remains: CONFIRMED')
            ->assertSee('Simulated holder BOOKING MODIFIED')
            ->assertSee('Current real holder remains: Booking Test')
            ->assertDontSee('test-secret')
            ->assertDontSee('test-api-key');

        $logs = HbxApiLog::query()->whereIn('operation', ['booking_detail', 'modification_simulation'])->get();
        $this->assertCount(2, $logs);

        foreach ($logs as $log) {
            $blob = ($log->request_payload ?? '').($log->response_payload ?? '').($log->error_message ?? '');
            $this->assertStringNotContainsString('test-secret', $blob);
            $this->assertStringNotContainsString('test-api-key', $blob);
            $this->assertStringNotContainsString('X-Signature', $blob);
        }
    }

    #[Test]
    public function modification_simulation_is_not_sent_when_booking_detail_fails(): void
    {
        $booking = $this->confirmedBooking();

        $this->replaceHttpFake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response('{"error":{"code":"INVALID_DATA","message":"Booking not found."}}', 400);
            }

            return Http::response(HbxFixture::booking(), 200);
        });

        $this->from(route('bookings.show', $booking))
            ->post(route('bookings.modify-simulation', $booking), [
                'holder_name' => 'BOOKING',
                'holder_surname' => 'MODIFIED',
            ])
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('hbx_error');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT');
        $this->assertSame(0, $booking->simulations()->count());
        $this->assertSame('CONFIRMED', $booking->fresh()->status);
        $this->assertSame('Test', $booking->fresh()->holder_surname);
    }

    #[Test]
    public function modification_simulation_is_blocked_when_hbx_disallows_modification(): void
    {
        $booking = $this->confirmedBooking();
        $detailJson = $this->liveDetail(modificationAllowed: false);

        $this->replaceHttpFake(function ($request) use ($detailJson) {
            if ($request->method() === 'GET') {
                return Http::response($detailJson, 200);
            }

            return Http::response($this->modificationSimulationResponse(), 200);
        });

        $this->from(route('bookings.show', $booking))
            ->post(route('bookings.modify-simulation', $booking), [
                'holder_name' => 'BOOKING',
                'holder_surname' => 'MODIFIED',
            ])
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('hbx_error');

        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT');
        $this->assertSame(0, $booking->simulations()->count());
        $this->assertSame('CONFIRMED', $booking->fresh()->status);
        $this->assertSame('Test', $booking->fresh()->holder_surname);
    }

    #[Test]
    public function actual_modification_stays_disabled(): void
    {
        $booking = $this->confirmedBooking();

        $this->from(route('bookings.show', $booking))
            ->post(route('bookings.modify', $booking))
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('hbx_error');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT');
        $this->assertSame('Test', $booking->fresh()->holder_surname);
    }

    private function searchPayload(array $overrides = []): array
    {
        return array_merge([
            'check_in' => now()->addDays(20)->toDateString(),
            'check_out' => now()->addDays(21)->toDateString(),
            'rooms' => 1,
            'adults' => 2,
            'children' => 0,
            'destination_code' => 'PMI',
            'hotel_code' => '',
        ], $overrides);
    }

    /**
     * Later Http::fake() calls append stubs, and the first match wins.
     * Booking tests that replace the availability stub must clear it first.
     *
     * @param  array<string, mixed>|callable  $callback
     */
    private function replaceHttpFake(array|callable $callback): void
    {
        $factory = Http::getFacadeRoot();
        (new \ReflectionProperty($factory, 'stubCallbacks'))->setValue($factory, new \Illuminate\Support\Collection);

        Http::fake($callback);
    }

    private function liveDetail(bool $modificationAllowed = true): string
    {
        $data = json_decode(HbxFixture::detail('1-9000001', 'CONFIRMED', 'Test'), true, 512, JSON_THROW_ON_ERROR);
        $data['booking']['creationUser'] = 'TEST.USER';
        $data['booking']['modificationPolicies']['modification'] = $modificationAllowed;

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function modificationSimulationResponse(): string
    {
        return json_encode([
            'auditData' => ['processTime' => '6', 'timestamp' => '2026-09-27 12:20:00.000'],
            'booking' => [
                'reference' => '1-9000001',
                'status' => 'CANCELLED',
                'holder' => ['name' => 'BOOKING', 'surname' => 'MODIFIED'],
                'hotel' => ['code' => 999, 'currency' => 'USD'],
                'currency' => 'USD',
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function bookingPayload(int $selectionId, string $token = '11111111-1111-4111-8111-111111111111'): array
    {
        return [
            'selection_id' => $selectionId,
            'holder_name' => 'Booking',
            'holder_surname' => 'Test',
            'remark' => 'Rento HBX local booking test',
            'submission_token' => $token,
            'guests' => [
                ['room_id' => 1, 'type' => 'AD', 'name' => 'First', 'surname' => 'Guest'],
                ['room_id' => 1, 'type' => 'AD', 'name' => 'Second', 'surname' => 'Guest'],
            ],
        ];
    }

    private function bookableSelection(): \App\Models\RateSelection
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response(HbxFixture::availability('RATEKEY-BOOKABLE'), 200),
        ]);

        $this->post(route('hotels.search.store'), $this->searchPayload());
        $searchId = \App\Models\HotelSearch::query()->value('id');
        $this->post(route('hotels.select', $searchId), ['rate_key' => 'RATEKEY-BOOKABLE']);

        return RateSelection::query()->firstOrFail();
    }

    private function confirmedBooking(): HotelBooking
    {
        Http::fake([
            'https://api.test.hotelbeds.com/hotel-api/1.0/hotels' => Http::response(HbxFixture::availability('RATEKEY-BOOKABLE'), 200),
            'https://api.test.hotelbeds.com/hotel-api/1.0/bookings' => Http::response(HbxFixture::booking('1-9000001'), 200),
        ]);

        $this->post(route('hotels.search.store'), $this->searchPayload());
        $searchId = \App\Models\HotelSearch::query()->value('id');
        $this->post(route('hotels.select', $searchId), ['rate_key' => 'RATEKEY-BOOKABLE']);
        $selection = RateSelection::query()->firstOrFail();
        $this->post(route('bookings.store'), $this->bookingPayload($selection->id));

        return HotelBooking::query()->firstOrFail();
    }
}
