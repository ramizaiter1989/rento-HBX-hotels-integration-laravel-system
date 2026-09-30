<?php

namespace Tests\Feature;

use App\DTOs\HBX\HbxResult;
use App\Models\ContentHotel;
use App\Models\ContentHotelRoom;
use App\Models\ContentHotelSnapshot;
use App\Services\HBX\HbxContentHotelImporter;
use App\Support\JsonDecimals;
use App\Models\HotelBooking;
use App\Models\HotelSearch;
use App\Models\HbxApiLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HbxContentSyncHotelsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_fifty_hotel_page_imports_once_and_does_not_keep_the_list_body(): void
    {
        $hotels = [];

        for ($code = 1; $code <= 50; $code++) {
            $hotels[] = $this->hotel($code);
        }

        $calls = 0;
        Http::fake(function (Request $request) use (&$calls, $hotels) {
            $calls++;
            $this->assertStringContainsString('from=1', $request->url());
            $this->assertStringContainsString('to=50', $request->url());
            $this->assertStringContainsString('fields=all', $request->url());
            $this->assertStringContainsString('language=ENG', $request->url());
            $this->assertStringContainsString('useSecondaryLanguage=false', $request->url());
            $this->assertStringNotContainsString('lastUpdateTime', $request->url());

            return Http::response($this->page($hotels, 1, 50, 305218), 200);
        });

        $this->artisan('hbx:content:sync-hotels', ['--limit' => 50])
            ->expectsOutputToContain('Page: 1-50 / 305218')
            ->expectsOutputToContain('Fetched: 50')
            ->expectsOutputToContain('Imported: 50')
            ->expectsOutputToContain('Failed: 0')
            ->expectsOutputToContain('HTTP requests: 1')
            ->assertSuccessful();

        $this->assertSame(1, $calls);
        $this->assertSame(50, ContentHotel::query()->count());

        $log = HbxApiLog::query()->where('operation', 'content_hotels')->firstOrFail();
        $this->assertStringNotContainsString('Hotel 1', (string) $log->response_payload);
        $this->assertStringNotContainsString('roomCode', (string) $log->response_payload);
        $this->assertStringContainsString('responseBytes', (string) $log->response_payload);
        $this->assertStringNotContainsString('test-api-key', (string) $log->request_payload.(string) $log->response_payload);
        $this->assertStringContainsString('Hotel 1', (string) ContentHotelSnapshot::query()->firstOrFail()->raw_payload);
    }

    #[Test]
    public function multiple_pages_follow_supplier_positions_until_the_limit(): void
    {
        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $query = $this->pageQuery($request);
            $calls[] = $query['from'].'-'.$query['to'];
            $from = (int) $query['from'];
            $hotels = [$this->hotel($from), $this->hotel($from + 1)];

            return Http::response($this->page($hotels, $from, $from + 1, 10), 200);
        });

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 2, '--limit' => 4])
            ->expectsOutputToContain('Page: 1-2 / 10')
            ->expectsOutputToContain('Page: 3-4 / 10')
            ->expectsOutputToContain('Fetched: 4')
            ->expectsOutputToContain('HTTP requests: 2')
            ->assertSuccessful();

        $this->assertSame(['1-2', '3-4'], $calls);
        $this->assertSame(4, ContentHotel::query()->count());
    }

    #[Test]
    public function supplier_total_ends_the_sync_on_a_short_last_page(): void
    {
        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $query = $this->pageQuery($request);
            $calls[] = $query['from'].'-'.$query['to'];
            $from = (int) $query['from'];

            if ($from === 1) {
                return Http::response($this->page([$this->hotel(11), $this->hotel(12)], 1, 2, 3), 200);
            }

            return Http::response($this->page([$this->hotel(13)], 3, 3, 3), 200);
        });

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 2])
            ->expectsOutputToContain('Fetched: 3')
            ->expectsOutputToContain('Imported: 3')
            ->expectsOutputToContain('HTTP requests: 2')
            ->assertSuccessful();

        $this->assertSame(['1-2', '3-3'], $calls);
        $this->assertSame([11, 12, 13], ContentHotel::query()->orderBy('hbx_hotel_code')->pluck('hbx_hotel_code')->all());
    }

    #[Test]
    public function the_limit_shrinks_the_last_request(): void
    {
        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $query = $this->pageQuery($request);
            $calls[] = $query['from'].'-'.$query['to'];
            $from = (int) $query['from'];
            $to = (int) $query['to'];
            $hotels = [];

            for ($code = $from; $code <= $to; $code++) {
                $hotels[] = $this->hotel(100 + $code);
            }

            return Http::response($this->page($hotels, $from, $to, 10), 200);
        });

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 2, '--limit' => 3])
            ->expectsOutputToContain('Fetched: 3')
            ->assertSuccessful();

        $this->assertSame(['1-2', '3-3'], $calls);
        $this->assertSame(3, ContentHotel::query()->count());
    }

    #[Test]
    public function last_update_is_sent_and_an_invalid_date_makes_no_request(): void
    {
        Http::fake(function (Request $request) {
            $this->assertStringContainsString('lastUpdateTime=2026-09-28', $request->url());

            return Http::response($this->page([$this->hotel(7)], 1, 1, 1), 200);
        });

        $this->artisan('hbx:content:sync-hotels', [
            '--batch' => 1,
            '--limit' => 1,
            '--last-update' => '2026-09-28',
        ])->expectsOutputToContain('Imported: 1')->assertSuccessful();

        Http::fake();

        $this->artisan('hbx:content:sync-hotels', ['--last-update' => '2026-02-31'])
            ->expectsOutputToContain('YYYY-MM-DD')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(1, ContentHotel::query()->count());
    }

    #[Test]
    public function flattened_list_codes_rooms_images_and_facilities_import(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($this->page([$this->richHotel()], 1, 1, 1), 200),
        ]);

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 1])
            ->expectsOutputToContain('Conflicts: 0')
            ->assertSuccessful();

        $hotel = ContentHotel::query()->where('hbx_hotel_code', 712)->firstOrFail();
        $this->assertSame('AE', $hotel->country_code);
        $this->assertSame('07', $hotel->state_code);
        $this->assertSame('PMI', $hotel->destination_code);
        $this->assertSame(90, $hotel->zone_code);
        $this->assertSame('4EST', $hotel->category_code);
        $this->assertSame('GRUPO7', $hotel->category_group_code);
        $this->assertSame('GLOB', $hotel->chain_code);
        $this->assertSame('A', $hotel->accommodation_type_code);
        $this->assertSame('39.36257000', $hotel->latitude);
        $this->assertSame(['BB', 'RO'], $hotel->boards()->orderBy('board_code')->pluck('board_code')->all());
        $this->assertSame([37, 81], $hotel->segments()->orderBy('segment_code')->pluck('segment_code')->all());

        $room = $hotel->rooms()->firstOrFail();
        $this->assertSame('APT.ST-18', $room->room_code);
        $this->assertSame('APT', $room->type_code);
        $this->assertSame('ST-18', $room->characteristic_code);
        $this->assertSame('SUITE', $room->pms_room_code);
        $this->assertSame('1 BEDROOM 2 ADULTS', $room->translations()->firstOrFail()->commercial_description);

        $image = $hotel->images()->firstOrFail();
        $this->assertSame('GEN', $image->image_type_code);
        $this->assertSame('00/000712/a.jpg', $image->path);
        $this->assertSame($room->id, $image->content_hotel_room_id);

        $facility = $hotel->facilities()->firstOrFail();
        $this->assertSame(535, $facility->facility_code);
        $this->assertSame(70, $facility->facility_group_code);
        $this->assertSame('22.00', $facility->amount);
        $this->assertSame('EUR', $facility->currency);

        $terminal = $hotel->terminals()->firstOrFail();
        $this->assertSame('ALC', $terminal->terminal_code);
        $this->assertNull($terminal->terminal_type);
        $this->assertSame(64, $terminal->distance);
        $this->assertSame('List Hotel Palma', $hotel->translations()->firstOrFail()->name);
    }

    #[Test]
    public function bulk_sync_keeps_a_hotel_details_snapshot(): void
    {
        $details = json_encode([
            'version' => '1.0',
            'hotel' => [
                'code' => 712,
                'name' => ['content' => 'Details Hotel'],
                'country' => ['code' => 'ES', 'isoCode' => 'ES'],
                'rooms' => [[
                    'roomCode' => 'SUI.ST',
                    'isParentRoom' => false,
                    'minPax' => 1,
                    'maxPax' => 2,
                    'minAdults' => 1,
                    'maxAdults' => 2,
                    'maxChildren' => 0,
                    'description' => 'SUITE STANDARD',
                    'type' => ['code' => 'SUI'],
                    'characteristic' => ['code' => 'ST'],
                ]],
                'terminals' => [[
                    'terminalCode' => 'PMI',
                    'terminalType' => 'A',
                    'distance' => 63,
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
        app(HbxContentHotelImporter::class)->import(new HbxResult(
            operation: 'content_hotel_detail',
            method: 'GET',
            endpoint: '/hotel-content-api/1.0/hotels/712/details',
            httpStatus: 200,
            data: JsonDecimals::decode($details),
            rawBody: $details,
            processTime: '10',
            durationMs: 1,
            supplierTimestamp: null,
        ), 'ENG', 712);

        $listHotel = $this->hotel(712, [
            'rooms' => [[
                'roomCode' => 'SUI.ST',
                'roomType' => 'SUI',
                'characteristicCode' => 'ST',
                'isParentRoom' => false,
                'minPax' => 1,
                'maxPax' => 2,
                'minAdults' => 1,
                'maxAdults' => 2,
                'maxChildren' => 0,
            ]],
            'terminals' => [[
                'terminalCode' => 'PMI',
                'distance' => 63,
            ]],
        ]);

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($this->page([$listHotel], 1, 1, 1), 200),
        ]);

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 1])
            ->expectsOutputToContain('Updated: 0')
            ->expectsOutputToContain('Unchanged: 1')
            ->expectsOutputToContain('Details retained: 1')
            ->expectsOutputToContain('Conflicts: 0')
            ->assertSuccessful();

        $this->assertSame('details', ContentHotelSnapshot::query()->value('content_origin'));
        $this->assertSame('SUITE STANDARD', ContentHotelRoom::query()->where('room_code', 'SUI.ST')->firstOrFail()->translations()->value('description'));
        $this->assertSame('A', ContentHotel::query()->firstOrFail()->terminals()->value('terminal_type'));
        $this->assertSame(0, HotelBooking::query()->count());
        $this->assertSame(0, HotelSearch::query()->count());
    }

    #[Test]
    public function a_real_supplier_mismatch_stays_under_conflicts(): void
    {
        $english = json_encode([
            'version' => '1.0',
            'hotel' => [
                'code' => 80,
                'name' => ['content' => 'Ranking Hotel'],
                'ranking' => 5,
            ],
        ], JSON_THROW_ON_ERROR);
        app(HbxContentHotelImporter::class)->import(new HbxResult(
            operation: 'content_hotel_detail',
            method: 'GET',
            endpoint: '/hotel-content-api/1.0/hotels/80/details',
            httpStatus: 200,
            data: JsonDecimals::decode($english),
            rawBody: $english,
            processTime: '10',
            durationMs: 1,
            supplierTimestamp: null,
        ), 'ENG', 80);

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($this->page([[
                'code' => 80,
                'name' => ['content' => 'فندق'],
                'ranking' => 9,
            ]], 1, 1, 1), 200),
        ]);

        $this->artisan('hbx:content:sync-hotels', ['--language' => 'ARA', '--batch' => 1, '--limit' => 1])
            ->expectsOutputToContain('Details retained: 0')
            ->expectsOutputToContain('Conflicts: 1')
            ->assertSuccessful();

        $this->assertSame(5, ContentHotel::query()->where('hbx_hotel_code', 80)->value('ranking'));
    }

    #[Test]
    public function a_hotels_list_page_keeps_repeated_image_rows(): void
    {
        $hotel = $this->hotel(63, [
            'rooms' => [[
                'roomCode' => 'DBL.ST',
                'roomType' => 'DBL',
                'characteristicCode' => 'ST',
                'isParentRoom' => false,
                'minPax' => 1,
                'maxPax' => 2,
                'minAdults' => 1,
                'maxAdults' => 2,
                'maxChildren' => 0,
            ], [
                'roomCode' => 'SUI.ST',
                'roomType' => 'SUI',
                'characteristicCode' => 'ST',
                'isParentRoom' => false,
                'minPax' => 1,
                'maxPax' => 2,
                'minAdults' => 1,
                'maxAdults' => 2,
                'maxChildren' => 0,
            ]],
            'images' => [
                $this->listImage('DBL.ST', 5),
                $this->listImage('SUI.ST', 1),
                $this->listImage('DBL.ST', 1),
                $this->listImage('DBL.ST', 1),
            ],
        ]);

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($this->page([$hotel], 1, 1, 1), 200),
        ]);

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 1])
            ->expectsOutputToContain('Imported: 1')
            ->assertSuccessful();

        $stored = ContentHotel::query()->where('hbx_hotel_code', 63)->firstOrFail();
        $images = $stored->images()->get();

        $this->assertSame([1, 2, 3, 0], $images->pluck('source_position')->all());
        $this->assertSame(['SUI.ST', 'DBL.ST', 'DBL.ST', 'DBL.ST'], $images->pluck('room_code')->all());
        $this->assertSame(4, $images->count());
        $this->assertSame(0, HotelBooking::query()->count());
        $this->assertSame(0, HotelSearch::query()->count());
    }

    #[Test]
    public function a_second_sync_of_the_same_page_is_unchanged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 18:00:00'));
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($this->page([$this->hotel(9)], 1, 1, 1), 200),
        ]);

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 1])
            ->expectsOutputToContain('Imported: 1')
            ->assertSuccessful();

        $hotelId = ContentHotel::query()->where('hbx_hotel_code', 9)->value('id');
        Carbon::setTestNow(Carbon::parse('2026-09-29 18:05:00'));

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 1])
            ->expectsOutputToContain('Imported: 0')
            ->expectsOutputToContain('Unchanged: 1')
            ->expectsOutputToContain('Updated: 0')
            ->assertSuccessful();

        $this->assertSame(1, ContentHotel::query()->count());
        $this->assertSame($hotelId, ContentHotel::query()->where('hbx_hotel_code', 9)->value('id'));
        $this->assertSame(
            '2026-09-29 18:05:00',
            ContentHotelSnapshot::query()->firstOrFail()->content_synced_at->format('Y-m-d H:i:s')
        );
    }

    #[Test]
    public function a_supplier_page_that_does_not_advance_cannot_loop(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls > 5) {
                throw new \RuntimeException('pagination loop');
            }

            return Http::response($this->page([$this->hotel(4)], 1, 1, 100), 200);
        });

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 50])
            ->assertSuccessful();

        $this->assertSame(2, $calls);
        $this->assertSame(1, ContentHotel::query()->count());
    }

    #[Test]
    public function one_invalid_hotel_does_not_remove_another_hotel(): void
    {
        $valid = $this->hotel(1, [
            'rooms' => [[
                'roomCode' => 'DBL.ST',
                'roomType' => 'DBL',
                'characteristicCode' => 'ST',
                'isParentRoom' => false,
                'minPax' => 1,
                'maxPax' => 2,
                'minAdults' => 1,
                'maxAdults' => 2,
                'maxChildren' => 0,
            ]],
        ]);
        $invalid = $this->hotel(2);
        unset($invalid['name']);

        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($this->page([$valid, $invalid], 1, 2, 2), 200),
        ]);

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 2, '--limit' => 2])
            ->expectsOutputToContain('Failed: 2 Content hotel is missing name.')
            ->expectsOutputToContain('Fetched: 2')
            ->expectsOutputToContain('Imported: 1')
            ->expectsOutputToContain('Failed: 1')
            ->assertFailed();

        $kept = ContentHotel::query()->where('hbx_hotel_code', 1)->firstOrFail();
        $this->assertNull(ContentHotel::query()->where('hbx_hotel_code', 2)->first());
        $this->assertSame('DBL.ST', $kept->rooms()->firstOrFail()->room_code);
        $this->assertSame(1, $kept->snapshots()->count());
    }

    #[Test]
    public function an_http_failure_stops_the_sync_after_the_imported_page(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::sequence()
                ->push($this->page([$this->hotel(5)], 1, 1, 4), 200)
                ->push(['error' => ['code' => 'PRODUCT_ERROR', 'message' => 'catalog unavailable']], 500)
                ->push(['error' => ['code' => 'PRODUCT_ERROR', 'message' => 'catalog unavailable']], 500)
                ->push(['error' => ['code' => 'PRODUCT_ERROR', 'message' => 'catalog unavailable']], 500),
        ]);

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 4])
            ->expectsOutputToContain('Imported: 1')
            ->expectsOutputToContain('HTTP requests: 2')
            ->assertFailed();

        $this->assertSame([5], ContentHotel::query()->pluck('hbx_hotel_code')->all());
        Http::assertSentCount(4);
    }

    #[Test]
    public function a_batch_above_the_configured_ceiling_is_rejected_before_any_request(): void
    {
        Http::fake();

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1000, '--limit' => 1])
            ->expectsOutputToContain('1 to 100')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, ContentHotel::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function listImage(string $roomCode, int $visualOrder): array
    {
        return [
            'imageTypeCode' => 'HAB',
            'path' => '00/000063/shared.jpg',
            'order' => 1,
            'visualOrder' => $visualOrder,
            'roomCode' => $roomCode,
            'roomType' => substr($roomCode, 0, 3),
            'characteristicCode' => 'ST',
        ];
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
            'categoryCode' => '4EST',
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function richHotel(): array
    {
        return [
            'code' => 712,
            'name' => ['content' => 'List Hotel Palma'],
            'description' => ['content' => 'A list description'],
            'country' => ['code' => 'AE'],
            'countryCode' => 'ES',
            'stateCode' => '07',
            'destinationCode' => 'PMI',
            'zoneCode' => 90,
            'categoryCode' => '4EST',
            'categoryGroupCode' => 'GRUPO7',
            'chainCode' => 'GLOB',
            'accommodationTypeCode' => 'A',
            'boardCodes' => ['BB', 'RO'],
            'segmentCodes' => [81, 37],
            'coordinates' => ['latitude' => 39.36257, 'longitude' => 2.294481],
            'phones' => [['phoneNumber' => '+34971000000', 'phoneType' => 'PHONEHOTEL']],
            'rooms' => [[
                'roomCode' => 'APT.ST-18',
                'isParentRoom' => false,
                'minPax' => 1,
                'maxPax' => 4,
                'maxAdults' => 4,
                'maxChildren' => 2,
                'minAdults' => 1,
                'roomType' => 'APT',
                'characteristicCode' => 'ST-18',
                'PMSRoomCode' => 'SUITE',
                'roomStays' => [[
                    'stayType' => 'BED',
                    'order' => '1',
                    'description' => 'Bed room',
                    'roomStayFacilities' => [['facilityCode' => 150, 'facilityGroupCode' => 61, 'number' => 1]],
                ]],
            ]],
            'facilities' => [[
                'facilityCode' => 535,
                'facilityGroupCode' => 70,
                'order' => 1,
                'number' => 1,
                'voucher' => false,
                'amount' => 22,
                'currency' => 'EUR',
                'applicationType' => 'UN',
            ]],
            'images' => [[
                'imageTypeCode' => 'GEN',
                'path' => '00/000712/a.jpg',
                'order' => 1,
                'visualOrder' => 1,
                'roomCode' => 'APT.ST-18',
                'roomType' => 'APT',
                'characteristicCode' => 'ST-18',
            ]],
            'wildcards' => [[
                'roomType' => 'APT.ST-18',
                'roomCode' => 'APT',
                'characteristicCode' => 'ST-18',
                'hotelRoomDescription' => ['content' => '1 BEDROOM 2 ADULTS'],
            ]],
            'terminals' => [['terminalCode' => 'ALC', 'distance' => 64]],
            'interestPoints' => [[
                'facilityCode' => 10,
                'facilityGroupCode' => 100,
                'order' => 1,
                'poiName' => 'Beach',
                'distance' => 200,
            ]],
            'lastUpdate' => '2026-09-28',
            'ranking' => 5,
        ];
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
            'auditData' => [
                'processTime' => '12',
                'timestamp' => '2026-09-29T10:00:00.000Z',
            ],
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
