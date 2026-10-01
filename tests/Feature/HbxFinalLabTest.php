<?php

namespace Tests\Feature;

use App\Exceptions\HBX\HbxValidationException;
use App\Models\ContentHotel;
use App\Models\ContentHotelFacility;
use App\Models\ContentHotelImage;
use App\Models\ContentHotelRoom;
use App\Models\ContentHotelRoomTranslation;
use App\Models\ContentHotelSnapshot;
use App\Models\ContentHotelTranslation;
use App\Models\ContentSyncFailure;
use App\Models\HotelSearch;
use App\Models\RateSelection;
use App\Services\HBX\ContentReferenceStore;
use App\Services\HBX\HbxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HbxFinalLabTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reference_sync_imports_every_catalog_once(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $row = fn (array $body) => Http::response([
                'from' => 1,
                'to' => 1,
                'total' => 1,
            ] + $body, 200);

            if (str_contains($path, 'facilitygroups')) {
                return $row(['facilityGroups' => [['code' => 20, 'description' => ['content' => 'Hotel facilities']]]]);
            }

            if (str_contains($path, 'facilities')) {
                return $row(['facilities' => [['code' => 10, 'facilityGroupCode' => 20, 'description' => ['content' => 'Wi-Fi']]]]);
            }

            if (str_contains($path, 'rooms')) {
                return $row(['rooms' => [[
                    'code' => 'DBL.ST',
                    'type' => 'DBL',
                    'characteristic' => 'ST',
                    'description' => ['content' => 'Double standard'],
                    'minPax' => 1,
                    'maxPax' => 2,
                ]]]);
            }

            if (str_contains($path, 'groupcategories')) {
                return $row(['groupCategories' => [['code' => 'GRUPO1', 'description' => ['content' => 'Stars']]]]);
            }

            if (str_contains($path, 'categories')) {
                return $row(['categories' => [['code' => '4EST', 'group' => 'GRUPO1', 'description' => ['content' => '4 stars']]]]);
            }

            if (str_contains($path, 'chains')) {
                return $row(['chains' => [['code' => 'CHAIN', 'description' => ['content' => 'Chain']]]]);
            }

            if (str_contains($path, 'accommodations')) {
                return $row(['accommodations' => [['code' => 'HOTEL', 'description' => ['content' => 'Hotel']]]]);
            }

            if (str_contains($path, 'boards')) {
                return $row(['boards' => [['code' => 'BB', 'description' => ['content' => 'Bed and breakfast']]]]);
            }

            if (str_contains($path, 'segments')) {
                return $row(['segments' => [['code' => 100, 'description' => ['content' => 'Beach']]]]);
            }

            if (str_contains($path, 'imagetypes')) {
                return $row(['imageTypes' => [['code' => 'GEN', 'description' => ['content' => 'General']]]]);
            }

            if (str_contains($path, 'countries')) {
                return $row(['countries' => [['code' => 'ES', 'description' => ['content' => 'Spain']]]]);
            }

            if (str_contains($path, 'destinations')) {
                return $row(['destinations' => [[
                    'code' => 'PMI',
                    'countryCode' => 'ES',
                    'description' => ['content' => 'Palma'],
                    'zones' => [['zoneCode' => 10, 'name' => 'Centre']],
                ]]]);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $this->artisan('hbx:content:sync-reference', ['--language' => 'ENG', '--batch' => 10])->assertSuccessful();
        $this->artisan('hbx:content:sync-reference', ['--language' => 'ENG', '--batch' => 10])->assertSuccessful();

        $tables = [
            'content_ref_facility_groups',
            'content_ref_facilities',
            'content_ref_rooms',
            'content_ref_room_types',
            'content_ref_room_characteristics',
            'content_ref_categories',
            'content_ref_category_groups',
            'content_ref_chains',
            'content_ref_accommodation_types',
            'content_ref_boards',
            'content_ref_segments',
            'content_ref_image_types',
            'content_ref_countries',
            'content_ref_destinations',
            'content_ref_zones',
        ];

        foreach ($tables as $table) {
            $this->assertSame(1, \Illuminate\Support\Facades\DB::table($table)->count(), $table);
        }

        $this->assertSame('DBL.ST', \Illuminate\Support\Facades\DB::table('content_ref_rooms')->value('code'));
        $this->assertSame('DBL', \Illuminate\Support\Facades\DB::table('content_ref_room_types')->value('code'));
        $this->assertSame('ST', \Illuminate\Support\Facades\DB::table('content_ref_room_characteristics')->value('code'));
        $this->assertSame('PMI', \Illuminate\Support\Facades\DB::table('content_ref_zones')->value('destination_code'));
        $this->assertSame(10, (int) \Illuminate\Support\Facades\DB::table('content_ref_zones')->value('zone_code'));
    }

    #[Test]
    public function reference_sync_imports_updates_and_keeps_languages_apart(): void
    {
        $english = 'Wi-Fi';
        Http::fake(function (Request $request) use (&$english) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $language = $query['language'] ?? 'ENG';
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_contains((string) $path, 'facilitygroups')) {
                return Http::response([
                    'from' => 1,
                    'to' => 1,
                    'total' => 1,
                    'facilityGroups' => [
                        ['code' => 20, 'description' => ['content' => 'Hotel facilities']],
                    ],
                ], 200);
            }

            return Http::response([
                'from' => 1,
                'to' => 1,
                'total' => 1,
                'facilities' => [
                    [
                        'code' => 10,
                        'facilityGroupCode' => 20,
                        'facilityTypologyCode' => 1,
                        'description' => ['content' => $language === 'ARA' ? 'Arabic Wi-Fi' : $english],
                    ],
                ],
            ], 200);
        });

        $this->artisan('hbx:content:sync-reference', ['--type' => 'facilities', '--language' => 'ENG', '--batch' => 10])
            ->assertSuccessful();

        $id = (int) \Illuminate\Support\Facades\DB::table('content_ref_facilities')->value('id');
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('content_ref_facilities')->count());

        $this->artisan('hbx:content:sync-reference', ['--type' => 'facilities', '--language' => 'ENG', '--batch' => 10])
            ->assertSuccessful();

        $this->assertSame($id, (int) \Illuminate\Support\Facades\DB::table('content_ref_facilities')->value('id'));
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('content_ref_facilities')->count());

        $english = 'Wireless';

        $this->artisan('hbx:content:sync-reference', ['--type' => 'facilities', '--language' => 'ENG', '--batch' => 10])
            ->assertSuccessful();
        $this->artisan('hbx:content:sync-reference', ['--type' => 'facilities', '--language' => 'ARA', '--batch' => 10])
            ->assertSuccessful();

        $this->assertSame($id, (int) \Illuminate\Support\Facades\DB::table('content_ref_facilities')->value('id'));
        $english = \Illuminate\Support\Facades\DB::table('content_ref_facility_translations')->where('language', 'ENG')->value('description');
        $arabic = \Illuminate\Support\Facades\DB::table('content_ref_facility_translations')->where('language', 'ARA')->value('description');
        $this->assertSame('Wireless', $english);
        $this->assertSame('Arabic Wi-Fi', $arabic);
    }

    #[Test]
    public function reference_get_retries_a_rate_limit_and_a_booking_write_does_not(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/hotel-content-api/*' => Http::sequence()
                ->push(['error' => 'rate'], 429)
                ->push([
                    'from' => 1,
                    'to' => 1,
                    'total' => 1,
                    'boards' => [
                        ['code' => 'BB', 'description' => ['content' => 'Bed and breakfast']],
                    ],
                ], 200),
            'https://api.test.hotelbeds.com/hotel-api/*' => Http::response(['error' => 'rejected'], 500),
        ]);

        $this->artisan('hbx:content:sync-reference', ['--type' => 'boards', '--batch' => 10])->assertSuccessful();
        $this->assertSame('Bed and breakfast', \Illuminate\Support\Facades\DB::table('content_ref_board_translations')->value('description'));

        try {
            app(HbxClient::class)->post('/hotel-api/1.0/bookings', ['holder' => ['name' => 'A']], 'booking');
            $this->fail('Booking HTTP 500 should fail without a retry.');
        } catch (\Throwable) {
            Http::assertSentCount(3);
        }
    }

    #[Test]
    public function an_unknown_reference_type_makes_no_supplier_call(): void
    {
        Http::fake();

        $this->expectException(HbxValidationException::class);
        $this->artisan('hbx:content:sync-reference', ['--type' => 'not-a-catalog']);
    }

    #[Test]
    public function content_lab_shows_reference_labels_and_survives_a_missing_label(): void
    {
        $resolved = false;
        $this->app->resolving(HbxClient::class, function () use (&$resolved): void {
            $resolved = true;
        });

        $store = app(ContentReferenceStore::class);
        $store->upsert('facility-groups', ['code' => 20], 'ENG', 'Hotel facilities');
        $store->upsert('facilities', ['code' => 10, 'facility_group_code' => 20], 'ENG', 'Wi-Fi', [
            'facility_typology_code' => 1,
        ]);
        $store->upsert('categories', ['code' => '4EST'], 'ENG', '4 STARS', [
            'category_group_code' => null,
        ]);

        $hotel = ContentHotel::query()->create([
            'hbx_hotel_code' => 712,
            'country_code' => 'ES',
            'destination_code' => 'PMI',
            'category_code' => '4EST',
        ]);
        ContentHotelTranslation::query()->create([
            'content_hotel_id' => $hotel->id,
            'language' => 'ENG',
            'name' => 'Labeled Hotel',
            'city' => 'Cala',
        ]);
        ContentHotelSnapshot::query()->create([
            'content_hotel_id' => $hotel->id,
            'language' => 'ENG',
            'raw_payload' => '{"secret":"SNAPSHOT-SECRET-712"}',
            'content_hash' => hash('sha256', 'snapshot'),
            'payload_bytes' => 10,
            'content_origin' => 'details',
            'content_synced_at' => now(),
        ]);
        ContentHotelFacility::query()->create([
            'content_hotel_id' => $hotel->id,
            'facility_code' => 10,
            'facility_group_code' => 20,
            'sort_order' => 1,
        ]);
        ContentHotelFacility::query()->create([
            'content_hotel_id' => $hotel->id,
            'facility_code' => 999,
            'facility_group_code' => 1,
            'sort_order' => 2,
        ]);

        $page = $this->get('/content/hotels/712');

        $page->assertOk();
        $page->assertSee('Wi-Fi');
        $page->assertSee('Hotel facilities');
        $page->assertSee('4 STARS');
        $page->assertSee('999');
        $page->assertDontSee('SNAPSHOT-SECRET-712');
        $page->assertDontSee('test-api-key');
        $page->assertDontSee('test-secret');
        $this->assertFalse($resolved);
        Http::assertNothingSent();
    }

    #[Test]
    public function availability_results_keep_an_unmatched_room_and_merge_on_supplier_codes(): void
    {
        $hotel = ContentHotel::query()->create([
            'hbx_hotel_code' => 712,
            'destination_code' => 'PMI',
            'category_code' => '4EST',
            'country_code' => 'ES',
        ]);
        ContentHotelTranslation::query()->create([
            'content_hotel_id' => $hotel->id,
            'language' => 'ENG',
            'name' => 'Stored Alua',
            'description' => 'Stored description snippet',
            'city' => 'Cala',
        ]);
        ContentHotelSnapshot::query()->create([
            'content_hotel_id' => $hotel->id,
            'language' => 'ENG',
            'raw_payload' => '{"secret":"SNAPSHOT-SECRET-712"}',
            'content_hash' => hash('sha256', 'a'),
            'payload_bytes' => 8,
            'content_origin' => 'details',
            'content_synced_at' => now(),
        ]);
        $room = ContentHotelRoom::query()->create([
            'content_hotel_id' => $hotel->id,
            'room_code' => 'SUI.ST',
            'type_code' => 'SUI',
            'characteristic_code' => 'ST',
            'is_parent_room' => false,
            'min_pax' => 1,
            'max_pax' => 2,
            'min_adults' => 1,
            'max_adults' => 2,
            'max_children' => 0,
        ]);
        ContentHotelRoomTranslation::query()->create([
            'content_hotel_room_id' => $room->id,
            'language' => 'ENG',
            'description' => 'SUITE STANDARD',
            'commercial_description' => '1 BEDROOM',
        ]);
        ContentHotelImage::query()->create([
            'content_hotel_id' => $hotel->id,
            'path' => '00/000712/a.jpg',
            'image_type_code' => 'HAB',
            'room_code' => 'UNKNOWN',
            'supplier_order' => 1,
            'visual_order' => 1,
            'source_position' => 0,
        ]);

        $payload = [
            'hotels' => [
                'checkIn' => '2026-10-15',
                'checkOut' => '2026-10-16',
                'total' => 2,
                'hotels' => [
                    [
                        'code' => 712,
                        'name' => 'Live Alua',
                        'categoryCode' => '4EST',
                        'destinationCode' => 'PMI',
                        'currency' => 'EUR',
                        'minRate' => '100.00',
                        'maxRate' => '180.00',
                        'rooms' => [
                            [
                                'code' => 'SUI.ST',
                                'name' => 'Suite',
                                'rates' => [[
                                    'rateKey' => 'rate-key-suite',
                                    'rateType' => 'BOOKABLE',
                                    'net' => '150.00',
                                    'boardCode' => 'BB',
                                    'boardName' => 'Bed',
                                ]],
                            ],
                            [
                                'code' => 'DBL.XX',
                                'name' => 'Double',
                                'rates' => [[
                                    'rateKey' => 'rate-key-double',
                                    'rateType' => 'BOOKABLE',
                                    'net' => '100.00',
                                    'boardCode' => 'RO',
                                    'boardName' => 'Room only',
                                ]],
                            ],
                        ],
                    ],
                    [
                        'code' => 11,
                        'name' => 'Live Only',
                        'currency' => 'EUR',
                        'minRate' => '80.00',
                        'maxRate' => '80.00',
                        'rooms' => [],
                    ],
                ],
            ],
        ];

        $search = HotelSearch::query()->create([
            'destination_code' => 'PMI',
            'check_in' => '2026-10-15',
            'check_out' => '2026-10-16',
            'rooms_count' => 1,
            'adults_count' => 2,
            'children_count' => 0,
            'request_payload' => '{}',
            'response_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'hotels_returned' => 2,
            'availability_source' => 'LIVE_HBX',
            'expires_at' => now()->addMinute(),
        ]);

        $results = $this->get(route('hotels.results', $search));
        $results->assertOk();
        $results->assertSee('Stored Alua');
        $results->assertSee('Content enrichment unavailable');
        $results->assertSee('Availability source');
        $results->assertSee('LIVE');
        $results->assertDontSee('SNAPSHOT-SECRET-712');
        $results->assertDontSee('rate-key-suite');

        $rooms = $this->get(route('hotels.rooms', ['search' => $search, 'hotelCode' => 712]));
        $rooms->assertOk();
        $rooms->assertSee('SUITE STANDARD');
        $rooms->assertSee('Content enrichment unavailable for room DBL.XX');
        $rooms->assertSee('Unmatched live room codes');
        $rooms->assertSee('DBL.XX');
        $rooms->assertSee('UNKNOWN');
        $rooms->assertSee('It is not shown on this page');
        $rooms->assertSee('type="hidden"', false);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_changed_checkrate_price_is_visible_before_booking(): void
    {
        $search = HotelSearch::query()->create([
            'check_in' => '2026-10-15',
            'check_out' => '2026-10-16',
            'rooms_count' => 1,
            'adults_count' => 2,
            'children_count' => 0,
            'request_payload' => '{}',
            'response_payload' => '{"hotels":{"total":0,"hotels":[]}}',
            'hotels_returned' => 0,
            'expires_at' => now()->addMinute(),
        ]);
        $selection = RateSelection::query()->create([
            'hotel_search_id' => $search->id,
            'hotel_code' => '712',
            'hotel_name' => 'Alua',
            'room_code' => 'SUI.ST',
            'room_name' => 'Suite',
            'original_rate_key' => 'rate-key',
            'rate_key' => 'rate-key-checked',
            'original_rate_type' => 'BOOKABLE',
            'rate_type' => 'BOOKABLE',
            'net' => '120.00',
            'availability_net' => '100.00',
            'currency' => 'EUR',
            'checkrate_completed_at' => now(),
            'valid_until' => now()->addMinute(),
        ]);

        $this->get(route('hotels.results', $search))->assertOk()->assertSee('Price changed');
        $this->get(route('bookings.create', ['selection' => $selection->id]))->assertOk()->assertSee('Price changed');
        Http::assertNothingSent();
    }

    #[Test]
    public function sync_dashboard_and_test_matrix_stay_local(): void
    {
        $this->get('/developer/hbx/sync')
            ->assertOk()
            ->assertSee('php artisan hbx:content:sync-reference --language=ENG')
            ->assertSee('Requested target')
            ->assertSee('Fetched total')
            ->assertSee('Remaining')
            ->assertSee('No sync runs stored')
            ->assertDontSee('test-api-key')
            ->assertDontSee('test-secret');

        $this->get('/developer/hbx/test-matrix')
            ->assertOk()
            ->assertSee('NOT IMPLEMENTED')
            ->assertSee('ACTUAL MODIFICATION')
            ->assertSee('LIVE VERIFIED')
            ->assertSee('PENDING LIVE VERIFICATION')
            ->assertDontSee('test-api-key')
            ->assertDontSee('test-secret');

        $this->get('/content/hotels/not-a-code')->assertNotFound();
        $this->get('/hotels/search/999999')->assertNotFound();
        Http::assertNothingSent();
    }

    #[Test]
    public function a_failed_hotel_import_is_stored_without_the_supplier_body(): void
    {
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response([
                'from' => 1,
                'to' => 1,
                'total' => 1,
                'hotels' => ['not-an-object'],
            ], 200),
        ]);

        $this->artisan('hbx:content:sync-hotels', ['--batch' => 1, '--limit' => 1])->assertFailed();

        $failure = ContentSyncFailure::query()->first();
        $this->assertNotNull($failure);
        $this->assertSame('invalid-payload', $failure->failure_type);
        $this->assertNull($failure->hbx_hotel_code);
        $this->assertStringNotContainsString('not-an-object', (string) $failure->message);
    }
}
