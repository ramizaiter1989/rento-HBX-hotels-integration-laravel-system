<?php

namespace Tests\Feature;

use App\Exceptions\HBX\HbxValidationException;
use App\Models\HbxApiLog;
use App\Models\HotelBooking;
use App\Models\HotelSearch;
use App\Models\RateSelection;
use App\Services\HBX\HbxBookingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\HbxFixture;
use Tests\TestCase;

class AvailabilityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        HotelBooking::flushEventListeners();
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_first_search_calls_hbx_and_stores_a_fingerprint_and_expiry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));
        $this->fakeAvailability();

        $response = $this->post(route('hotels.search.store'), $this->searchPayload());

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Fresh HBX availability received.');
        $response->assertSessionHas('availability_source', 'LIVE_HBX');
        Http::assertSentCount(1);

        $search = HotelSearch::query()->firstOrFail();
        $this->assertSame(64, strlen((string) $search->search_fingerprint));
        $this->assertSame('2026-09-27 12:01:00', $search->expires_at?->toDateTimeString());
        $this->assertSame('2026-09-27 12:00:00', $search->last_accessed_at?->toDateTimeString());
        $this->assertTrue($search->isFresh());

        $page = $this->get(route('hotels.results', $search));
        $page->assertOk();
        $page->assertSee('Fresh HBX availability received.');
        $page->assertSee('Availability source');
        $page->assertSee('LIVE');
        $page->assertSee('2026-09-27 12:01:00');
    }

    #[Test]
    public function the_same_search_inside_the_ttl_reuses_the_snapshot_without_extending_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));
        $this->fakeAvailability();
        $this->post(route('hotels.search.store'), $this->searchPayload())->assertRedirect();
        $original = HotelSearch::query()->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:30'));
        $response = $this->post(route('hotels.search.store'), $this->searchPayload());

        $response->assertRedirect(route('hotels.results', $original));
        $response->assertSessionHas('status', 'Reused a fresh availability snapshot.');
        $response->assertSessionHas('availability_source', 'CACHE_HIT');
        Http::assertSentCount(1);
        $this->assertSame(1, HotelSearch::query()->count());

        $original->refresh();
        $this->assertSame('2026-09-27 12:01:00', $original->expires_at?->toDateTimeString());
        $this->assertSame('2026-09-27 12:00:30', $original->last_accessed_at?->toDateTimeString());
    }

    #[Test]
    public function the_same_search_after_the_ttl_calls_hbx_again(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));
        $this->fakeAvailability();
        $this->post(route('hotels.search.store'), $this->searchPayload());

        Carbon::setTestNow(Carbon::parse('2026-09-27 12:01:01'));
        $this->post(route('hotels.search.store'), $this->searchPayload())->assertRedirect();

        Http::assertSentCount(2);
        $this->assertSame(2, HotelSearch::query()->count());
        $latest = HotelSearch::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('2026-09-27 12:02:01', $latest->expires_at?->toDateTimeString());
    }

    #[Test]
    public function a_materially_different_search_calls_hbx_again(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));
        $this->fakeAvailability();
        $this->post(route('hotels.search.store'), $this->searchPayload());
        $this->post(route('hotels.search.store'), $this->searchPayload(['adults' => 3]))->assertRedirect();

        Http::assertSentCount(2);
        $this->assertSame(2, HotelSearch::query()->count());
    }

    #[Test]
    public function a_legacy_snapshot_without_a_fingerprint_is_never_reused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));
        $legacy = $this->storedSearch('RATEKEY-LEGACY', null);
        $this->fakeAvailability();

        $this->post(route('hotels.search.store'), $this->searchPayload())->assertRedirect();

        Http::assertSentCount(1);
        $this->assertSame(2, HotelSearch::query()->count());
        $legacy->refresh();
        $this->assertNull($legacy->search_fingerprint);
        $this->assertNull($legacy->expires_at);
        $this->assertFalse($legacy->isFresh());
    }

    #[Test]
    public function an_expired_snapshot_stays_viewable_but_cannot_start_checkrate_or_selection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));
        $search = $this->storedSearch('RATEKEY-EXPIRED', Carbon::parse('2026-09-27 11:00:00'));
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response(HbxFixture::checkRate('RATEKEY-EXPIRED'), 200),
        ]);

        $page = $this->get(route('hotels.results', $search));
        $page->assertOk();
        $page->assertSee('Test Hotel');
        $page->assertSee('Historical availability snapshot. Run a new search before selecting a rate.');
        $page->assertSee('2026-09-27 11:00:00');

        $this->get(route('hotels.results', ['search' => $search, 'page' => 2]))->assertOk();
        $this->get(route('developer.search-raw', $search))->assertOk()->assertSee('RATEKEY-EXPIRED');

        $rooms = $this->get(route('hotels.rooms', ['search' => $search, 'hotelCode' => 712]));
        $rooms->assertOk();
        $rooms->assertSee('Historical availability snapshot. Run a new search before selecting a rate.');
        $rooms->assertDontSee(route('hotels.check-rate', $search));
        $rooms->assertDontSee(route('hotels.select', $search));

        $select = $this->from(route('hotels.results', $search))
            ->post(route('hotels.select', $search), ['rate_key' => 'RATEKEY-EXPIRED']);
        $select->assertRedirect(route('hotels.results', $search));
        $select->assertSessionHas('hbx_error', function (array $error): bool {
            return $error['code'] === 'AVAILABILITY_SNAPSHOT_EXPIRED'
                && $error['message'] === 'This availability snapshot has expired. Run a new hotel search before selecting or checking this rate.';
        });

        $check = $this->from(route('hotels.results', $search))
            ->post(route('hotels.check-rate', $search), ['rate_key' => 'RATEKEY-EXPIRED']);
        $check->assertRedirect(route('hotels.results', $search));
        $check->assertSessionHas('hbx_error', function (array $error): bool {
            return $error['code'] === 'AVAILABILITY_SNAPSHOT_EXPIRED';
        });

        Http::assertNothingSent();
        $this->assertSame(0, RateSelection::query()->count());
    }

    #[Test]
    public function a_direct_selection_expires_with_the_snapshot_and_blocks_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));
        Http::fake([
            'https://api.test.hotelbeds.com/hotel-api/1.0/hotels' => Http::response(HbxFixture::availability('RATEKEY-BOOKABLE'), 200),
            'https://api.test.hotelbeds.com/hotel-api/1.0/bookings' => Http::response(HbxFixture::booking('1-9000001'), 200),
        ]);

        $this->post(route('hotels.search.store'), $this->searchPayload());
        $search = HotelSearch::query()->firstOrFail();
        $this->post(route('hotels.select', $search), ['rate_key' => 'RATEKEY-BOOKABLE'])->assertRedirect();
        $selection = RateSelection::query()->firstOrFail();
        $this->assertTrue($selection->valid_until?->equalTo($search->expires_at));

        Carbon::setTestNow(Carbon::parse('2026-09-27 12:01:01'));
        $blocked = $this->from(route('bookings.create', ['selection' => $selection->id]))
            ->post(route('bookings.store'), $this->bookingPayload($selection->id));

        $blocked->assertRedirect();
        $blocked->assertSessionHas('hbx_error', function (array $error): bool {
            return $error['code'] === 'RATE_SELECTION_EXPIRED'
                && $error['message'] === 'This rate selection has expired. Run a fresh hotel search or CheckRate before booking.';
        });
        $this->assertSame(0, HotelBooking::query()->count());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/bookings'));
    }

    #[Test]
    public function an_expiry_after_the_pending_row_is_saved_fails_that_booking_without_calling_hbx(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));
        Http::fake([
            'https://api.test.hotelbeds.com/hotel-api/1.0/bookings' => Http::response(HbxFixture::booking('1-9000001'), 200),
        ]);

        $search = $this->storedSearch('RATEKEY-RACE', Carbon::parse('2026-09-27 12:01:00'));
        $selection = RateSelection::query()->create([
            'hotel_search_id' => $search->id,
            'original_rate_key' => 'RATEKEY-RACE',
            'rate_key' => 'RATEKEY-RACE',
            'original_rate_type' => 'BOOKABLE',
            'rate_type' => 'BOOKABLE',
            'valid_until' => Carbon::parse('2026-09-27 12:01:00'),
            'hotel_code' => '712',
            'hotel_name' => 'Test Hotel',
            'net' => '121.18',
            'currency' => 'EUR',
        ]);
        $this->assertTrue($selection->isWithinValidity());

        $persistedAsPending = false;
        HotelBooking::created(function (HotelBooking $booking) use (&$persistedAsPending): void {
            $persistedAsPending = $booking->status === HotelBooking::STATUS_PENDING;
            Carbon::setTestNow(Carbon::parse('2026-09-27 12:02:00'));
        });

        $token = '55555555-5555-4555-8555-555555555555';
        $guests = [
            ['room_id' => 1, 'type' => 'AD', 'name' => 'First', 'surname' => 'Guest'],
            ['room_id' => 1, 'type' => 'AD', 'name' => 'Second', 'surname' => 'Guest'],
        ];

        try {
            app(HbxBookingService::class)->book($selection, 'Booking', 'Test', $guests, 'Rento HBX local booking test', $token);
            $this->fail('The second freshness check should reject the expired rate.');
        } catch (HbxValidationException $exception) {
            $this->assertSame('RATE_SELECTION_EXPIRED', $exception->supplierCode);
            $this->assertNotSame('AMBIGUOUS_RESULT', $exception->supplierCode);
        }

        $this->assertTrue($persistedAsPending);
        $this->assertTrue(Carbon::now()->greaterThan($selection->valid_until));

        $booking = HotelBooking::query()->where('submission_token', $token)->firstOrFail();
        $this->assertSame(HotelBooking::STATUS_FAILED, $booking->status);
        $this->assertNotSame(HotelBooking::STATUS_PENDING, $booking->status);
        $this->assertNotSame(HotelBooking::STATUS_AMBIGUOUS, $booking->status);
        $this->assertSame('Booking', $booking->holder_name);
        $this->assertSame(2, $booking->rooms()->firstOrFail()->guests()->count());
        $this->assertNull($booking->hbx_reference);
        $this->assertNull($booking->raw_booking_response);
        Http::assertNothingSent();

        $selection->forceFill(['valid_until' => Carbon::parse('2026-09-27 13:00:00')])->save();
        $replay = app(HbxBookingService::class)->book(
            $selection->fresh(),
            'Booking',
            'Test',
            $guests,
            'Rento HBX local booking test',
            $token,
        );

        $this->assertSame($booking->id, $replay->id);
        $this->assertSame(HotelBooking::STATUS_FAILED, $replay->fresh()->status);
        $this->assertSame(1, HotelBooking::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_null_valid_until_blocks_a_new_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));
        Http::fake([
            'https://api.test.hotelbeds.com/hotel-api/1.0/hotels' => Http::response(HbxFixture::availability('RATEKEY-BOOKABLE'), 200),
            'https://api.test.hotelbeds.com/hotel-api/1.0/bookings' => Http::response(HbxFixture::booking('1-9000001'), 200),
        ]);

        $this->post(route('hotels.search.store'), $this->searchPayload());
        $search = HotelSearch::query()->firstOrFail();
        $this->post(route('hotels.select', $search), ['rate_key' => 'RATEKEY-BOOKABLE']);
        $selection = RateSelection::query()->firstOrFail();
        $selection->forceFill(['valid_until' => null])->save();

        $this->post(route('bookings.store'), $this->bookingPayload($selection->id, '22222222-2222-4222-8222-222222222222'))
            ->assertSessionHas('hbx_error', fn (array $error): bool => $error['code'] === 'RATE_SELECTION_EXPIRED');

        $this->assertSame(0, HotelBooking::query()->count());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/bookings'));
    }

    #[Test]
    public function a_successful_checkrate_refreshes_validity_and_an_expired_selection_cannot_replace_a_confirmed_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));
        Http::fake([
            'https://api.test.hotelbeds.com/hotel-api/1.0/hotels' => Http::response(HbxFixture::availability('RATEKEY-ORIGINAL'), 200),
            'https://api.test.hotelbeds.com/hotel-api/1.0/checkrates' => Http::response(HbxFixture::checkRate('RATEKEY-CHECKED'), 200),
            'https://api.test.hotelbeds.com/hotel-api/1.0/bookings' => Http::response(HbxFixture::booking('1-9000001'), 200),
        ]);

        $this->post(route('hotels.search.store'), $this->searchPayload());
        $search = HotelSearch::query()->firstOrFail();
        $this->post(route('hotels.check-rate', $search), ['rate_key' => 'RATEKEY-ORIGINAL'])->assertRedirect();

        $selection = RateSelection::query()->firstOrFail();
        $this->assertSame('RATEKEY-ORIGINAL', $selection->original_rate_key);
        $this->assertSame('RATEKEY-CHECKED', $selection->rate_key);
        $this->assertNotNull($selection->checkrate_completed_at);
        $this->assertSame('2026-09-27 12:05:00', $selection->valid_until?->toDateTimeString());

        Carbon::setTestNow(Carbon::parse('2026-09-27 12:01:01'));
        $search->refresh();
        $this->assertTrue($search->isExpired());
        $this->post(route('bookings.store'), $this->bookingPayload($selection->id))->assertRedirect();

        $booking = HotelBooking::query()->firstOrFail();
        $this->assertSame('CONFIRMED', $booking->status);
        $this->assertSame('1-9000001', $booking->hbx_reference);

        Carbon::setTestNow(Carbon::parse('2026-09-27 12:05:01'));
        $this->post(route('bookings.store'), $this->bookingPayload($selection->id, '33333333-3333-4333-8333-333333333333'))
            ->assertSessionHas('hbx_error', fn (array $error): bool => $error['code'] === 'RATE_SELECTION_EXPIRED');

        $booking->refresh();
        $this->assertSame('CONFIRMED', $booking->status);
        $this->assertSame('1-9000001', $booking->hbx_reference);
        $this->assertSame(1, HotelBooking::query()->count());
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn (array $pair): bool => str_contains($pair[0]->url(), '/bookings')
        )->count());
    }

    #[Test]
    public function availability_logs_keep_a_summary_and_the_search_keeps_the_raw_body(): void
    {
        $payload = HbxFixture::availability('RATEKEY-LOGGED');
        Http::fake([
            'https://api.test.hotelbeds.com/hotel-api/1.0/hotels' => Http::response($payload, 200),
        ]);

        $this->post(route('hotels.search.store'), $this->searchPayload())->assertRedirect();

        $search = HotelSearch::query()->firstOrFail();
        $log = HbxApiLog::query()->where('operation', 'availability')->firstOrFail();
        $summary = json_decode((string) $log->response_payload, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($payload, $search->response_payload);
        $this->assertTrue($log->successful);
        $this->assertSame(200, $log->http_status);
        $this->assertSame('20', $log->supplier_process_time);
        $this->assertNotNull($log->local_duration_ms);
        $this->assertTrue($summary['bodyStoredInHotelSearch']);
        $this->assertSame(strlen($payload), $summary['responseBytes']);
        $this->assertSame(1, $summary['hotelsTotal']);
        $this->assertSame('20', $summary['processTime']);
        $this->assertSame('2026-09-27 12:00:00.000', $summary['timestamp']);
        $this->assertArrayNotHasKey('hotels', $summary);
        $this->assertStringNotContainsString('RATEKEY-LOGGED', (string) $log->response_payload);
        $this->assertStringNotContainsString('"rooms"', (string) $log->response_payload);
        $this->assertStringNotContainsString('test-api-key', (string) $log->response_payload.(string) $log->request_payload);
        $this->assertStringNotContainsString('test-secret', (string) $log->response_payload.(string) $log->request_payload);
    }

    #[Test]
    public function cleanup_deletes_only_old_unbooked_snapshots_and_old_availability_logs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));

        $fresh = $this->storedSearch('RATEKEY-FRESH', Carbon::parse('2026-09-27 12:01:00'));
        $recentExpired = $this->storedSearch('RATEKEY-RECENT', Carbon::parse('2026-09-27 11:00:00'));
        $recentExpired->forceFill(['created_at' => Carbon::parse('2026-09-26 12:00:00')])->save();

        $oldUnbooked = $this->storedSearch('RATEKEY-OLD', Carbon::parse('2026-09-18 12:00:00'));
        $oldUnbooked->forceFill(['created_at' => Carbon::parse('2026-09-18 12:00:00')])->save();
        $oldSelection = RateSelection::query()->create($this->selectionAttributes($oldUnbooked, 'RATEKEY-OLD'));

        $oldBooked = $this->storedSearch('RATEKEY-BOOKED', Carbon::parse('2026-09-18 12:00:00'));
        $oldBooked->forceFill(['created_at' => Carbon::parse('2026-09-18 12:00:00')])->save();
        $keptSelection = RateSelection::query()->create($this->selectionAttributes($oldBooked, 'RATEKEY-BOOKED'));
        $booking = HotelBooking::query()->create([
            'hotel_search_id' => $oldBooked->id,
            'rate_selection_id' => $keptSelection->id,
            'client_reference' => 'RENTO-260927-KEEP001',
            'submission_token' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'status' => 'CONFIRMED',
            'holder_name' => 'Keep',
            'holder_surname' => 'Booking',
        ]);

        $oldAvailabilityLog = $this->log('availability', '2026-09-18 12:00:00');
        $recentAvailabilityLog = $this->log('availability', '2026-09-27 12:00:00');
        $bookingLog = $this->log('booking', '2026-09-18 12:00:00');
        $checkRateLog = $this->log('checkrate', '2026-09-18 12:00:00');

        $this->artisan('hbx:availability:cleanup', ['--dry-run' => true])
            ->expectsOutputToContain('Unbooked hotel searches eligible: 1')
            ->expectsOutputToContain('Booked hotel searches kept: 1')
            ->expectsOutputToContain('Availability logs eligible: 1')
            ->expectsOutputToContain('Dry run: nothing deleted.')
            ->assertSuccessful();

        $this->assertSame(4, HotelSearch::query()->count());
        $this->assertNotNull(RateSelection::query()->find($oldSelection->id));
        $this->assertNotNull(HbxApiLog::query()->find($oldAvailabilityLog->id));

        $this->artisan('hbx:availability:cleanup')
            ->expectsOutputToContain('Unbooked hotel searches deleted: 1')
            ->expectsOutputToContain('Availability logs deleted: 1')
            ->assertSuccessful();

        $this->assertNotNull(HotelSearch::query()->find($fresh->id));
        $this->assertNotNull(HotelSearch::query()->find($recentExpired->id));
        $this->assertNull(HotelSearch::query()->find($oldUnbooked->id));
        $this->assertNull(RateSelection::query()->find($oldSelection->id));
        $this->assertNotNull(HotelSearch::query()->find($oldBooked->id));
        $this->assertNotNull(RateSelection::query()->find($keptSelection->id));
        $booking->refresh();
        $this->assertSame('CONFIRMED', $booking->status);
        $this->assertSame($oldBooked->id, $booking->hotel_search_id);
        $this->assertNull(HbxApiLog::query()->find($oldAvailabilityLog->id));
        $this->assertNotNull(HbxApiLog::query()->find($recentAvailabilityLog->id));
        $this->assertNotNull(HbxApiLog::query()->find($bookingLog->id));
        $this->assertNotNull(HbxApiLog::query()->find($checkRateLog->id));
    }

    #[Test]
    public function availability_cleanup_is_scheduled_daily_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => str_contains((string) $event->command, 'hbx:availability:cleanup')
        );

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function searchPayload(array $overrides = []): array
    {
        return array_merge([
            'check_in' => '2026-10-11',
            'check_out' => '2026-10-12',
            'rooms' => 1,
            'adults' => 2,
            'children' => 0,
            'destination_code' => 'PMI',
            'hotel_code' => '',
        ], $overrides);
    }

    private function fakeAvailability(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/hotel-api/1.0/hotels' => Http::response(HbxFixture::availability('RATEKEY-BOOKABLE'), 200),
        ]);
    }

    private function storedSearch(string $rateKey, ?Carbon $expiresAt): HotelSearch
    {
        return HotelSearch::query()->create([
            'destination_code' => 'PMI',
            'check_in' => '2026-10-11',
            'check_out' => '2026-10-12',
            'rooms_count' => 1,
            'adults_count' => 2,
            'children_count' => 0,
            'request_payload' => '{}',
            'response_payload' => HbxFixture::availability($rateKey),
            'hotels_returned' => 1,
            'search_fingerprint' => null,
            'expires_at' => $expiresAt,
            'last_accessed_at' => $expiresAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function selectionAttributes(HotelSearch $search, string $rateKey): array
    {
        return [
            'hotel_search_id' => $search->id,
            'original_rate_key' => $rateKey,
            'rate_key' => $rateKey,
            'original_rate_type' => 'BOOKABLE',
            'rate_type' => 'BOOKABLE',
            'valid_until' => Carbon::parse('2026-09-18 12:01:00'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
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

    private function log(string $operation, string $createdAt): HbxApiLog
    {
        $log = HbxApiLog::query()->create([
            'operation' => $operation,
            'request_method' => 'POST',
            'endpoint' => '/hotel-api/1.0/'.$operation,
            'http_status' => 200,
            'successful' => true,
            'response_payload' => '{"kept":true}',
        ]);
        $log->forceFill(['created_at' => Carbon::parse($createdAt)])->save();

        return $log;
    }
}
