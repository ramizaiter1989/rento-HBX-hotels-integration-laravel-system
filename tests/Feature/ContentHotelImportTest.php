<?php

namespace Tests\Feature;

use App\DTOs\HBX\ContentImportResult;
use App\DTOs\HBX\HbxResult;
use App\Exceptions\HBX\HbxValidationException;
use App\Models\ContentHotel;
use App\Models\ContentHotelFacility;
use App\Models\ContentHotelImage;
use App\Models\ContentHotelRoom;
use App\Models\ContentHotelSnapshot;
use App\Models\ContentRoomStay;
use App\Models\ContentRoomStayFacility;
use App\Models\HotelBooking;
use App\Models\HotelSearch;
use App\Models\RateSelection;
use App\Services\HBX\HbxContentHotelImporter;
use App\Support\ContentHotelHasher;
use App\Support\JsonDecimals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ContentHotelImportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ContentHotelImage::flushEventListeners();

        parent::tearDown();
    }

    #[Test]
    public function content_tables_exist_with_the_approved_keys(): void
    {
        foreach ([
            'content_hotels',
            'content_hotel_translations',
            'content_hotel_snapshots',
            'content_hotel_phones',
            'content_hotel_boards',
            'content_hotel_segments',
            'content_hotel_rooms',
            'content_hotel_room_translations',
            'content_hotel_facilities',
            'content_room_facilities',
            'content_room_stays',
            'content_room_stay_facilities',
            'content_hotel_images',
            'content_hotel_terminals',
            'content_hotel_interest_points',
            'content_hotel_interest_point_translations',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertFalse(Schema::hasTable('content_hotel_wildcards'));
        $this->assertFalse(Schema::hasColumn('content_hotel_facilities', 'description'));
        $this->assertFalse(Schema::hasColumn('content_hotel_boards', 'description'));
        $this->assertFalse(Schema::hasColumn('content_hotel_images', 'image_type_name'));
        $this->assertTrue(Schema::hasColumn('content_hotel_room_translations', 'commercial_description'));

        $hotelIndexes = collect(Schema::getIndexes('content_hotels'));
        $this->assertTrue($hotelIndexes->contains(
            fn (array $index): bool => ($index['unique'] ?? false) === true && $index['columns'] === ['hbx_hotel_code']
        ));

        $roomIndexes = collect(Schema::getIndexes('content_hotel_rooms'));
        $this->assertTrue($roomIndexes->contains(
            fn (array $index): bool => ($index['unique'] ?? false) === true && $index['columns'] === ['content_hotel_id', 'room_code']
        ));
        $this->assertFalse($roomIndexes->contains(
            fn (array $index): bool => ($index['unique'] ?? false) === true && $index['columns'] === ['pms_room_code']
        ));

        $imageIndexes = collect(Schema::getIndexes('content_hotel_images'));
        $this->assertFalse($imageIndexes->contains(
            fn (array $index): bool => ($index['unique'] ?? false) === true && $index['columns'] === ['path']
        ));
        $this->assertFalse($imageIndexes->contains(
            fn (array $index): bool => ($index['unique'] ?? false) === true && $index['columns'] === ['content_hotel_id', 'path', 'visual_order']
        ));
        $this->assertTrue($imageIndexes->contains(
            fn (array $index): bool => ($index['unique'] ?? false) === true && $index['columns'] === ['content_hotel_id', 'source_position']
        ));
        $this->assertTrue($imageIndexes->contains(
            fn (array $index): bool => ($index['unique'] ?? false) === false && $index['columns'] === ['content_hotel_id', 'path']
        ));
        $this->assertTrue($imageIndexes->contains(
            fn (array $index): bool => ($index['unique'] ?? false) === false && $index['columns'] === ['content_hotel_id', 'visual_order']
        ));
    }

    #[Test]
    public function the_eng_fixture_imports_hotel_712_with_rooms_wildcards_and_a_snapshot(): void
    {
        $raw = $this->fixture();
        $outcome = $this->importer()->import($this->contentResult($raw), 'ENG', 712);

        $this->assertSame(ContentImportResult::IMPORTED, $outcome->status);
        $this->assertSame(712, $outcome->hotelCode);
        $this->assertSame('ENG', $outcome->language);
        $this->assertSame(37, $outcome->rooms);
        $this->assertSame(312, $outcome->images);
        $this->assertSame(
            range(0, 311),
            ContentHotelImage::query()->orderBy('source_position')->pluck('source_position')->all()
        );
        $this->assertSame(77, $outcome->facilities);
        $this->assertSame(strlen($raw), $outcome->snapshotBytes);
        $this->assertSame((new ContentHotelHasher)->hash($raw), $outcome->hash);
        $this->assertSame([], $outcome->unmatchedWildcards);

        $hotel = ContentHotel::query()->where('hbx_hotel_code', 712)->firstOrFail();
        $this->assertSame('ES', $hotel->country_code);
        $this->assertSame('PMI', $hotel->destination_code);
        $this->assertSame(90, $hotel->zone_code);
        $this->assertSame('4EST', $hotel->category_code);
        $this->assertSame('ALUAR', $hotel->chain_code);
        $this->assertSame('P', $hotel->accommodation_type_code);
        $this->assertSame(3117, $hotel->giata_code);
        $this->assertSame('2026-09-16', $hotel->supplier_last_update?->format('Y-m-d'));
        $this->assertSame('39.36257000', $hotel->latitude);
        $this->assertSame('3.23084200', $hotel->longitude);
        $this->assertSame('Alua Suites las Rocas', $hotel->translations()->where('language', 'ENG')->value('name'));
        $this->assertSame('CALA D\'OR', $hotel->translations()->where('language', 'ENG')->value('city'));

        $room = ContentHotelRoom::query()->where('room_code', 'SUI.ST')->firstOrFail();
        $translation = $room->translations()->where('language', 'ENG')->firstOrFail();
        $this->assertSame('SUITE STANDARD', $translation->description);
        $this->assertSame('1 BEDROOM 2 ADULTS', $translation->commercial_description);
        $this->assertSame('SUITE', $room->pms_room_code);
        $this->assertSame(7, ContentHotelRoom::query()->where('pms_room_code', 'SUITE')->count());
        $this->assertSame(7, ContentHotelRoom::query()->where('pms_room_code', 'AP1S')->count());
        $this->assertSame(14, ContentHotelRoom::query()->whereNull('pms_room_code')->count());

        $this->assertSame(2, $hotel->phones()->count());
        $this->assertSame(4, $hotel->boards()->count());
        $this->assertSame(4, $hotel->segments()->count());
        $this->assertSame(1, $hotel->terminals()->count());
        $this->assertSame(2, $hotel->interestPoints()->count());
        $this->assertSame(25, ContentRoomStay::query()->count());
        $this->assertSame(43, ContentRoomStayFacility::query()->count());

        $snapshot = ContentHotelSnapshot::query()->where('language', 'ENG')->firstOrFail();
        $this->assertSame($outcome->hash, $snapshot->content_hash);
        $this->assertStringContainsString('"hotel"', $snapshot->raw_payload);
        $this->assertStringContainsString('processTime', $snapshot->raw_payload);
        $this->assertStringNotContainsString('requestHost', $snapshot->raw_payload);

        Http::assertNothingSent();
    }

    #[Test]
    public function supplier_image_and_facility_quirks_are_preserved(): void
    {
        $this->importer()->import($this->contentResult($this->fixture()), 'ENG');

        $orphans = ContentHotelImage::query()->where('room_code', 'SUI.VM-14')->get();
        $this->assertNotEmpty($orphans);
        $this->assertTrue($orphans->every(fn (ContentHotelImage $image): bool => $image->content_hotel_room_id === null));

        $sharedPath = ContentHotelImage::query()
            ->where('path', '00/000712/000712a_hb_ro_260.jpg')
            ->orderBy('visual_order')
            ->orderBy('source_position')
            ->get();
        $this->assertCount(2, $sharedPath);
        $this->assertEqualsCanonicalizing(['SUI.ST-2', 'SUI.ST-3'], $sharedPath->pluck('room_code')->all());

        $pets = ContentHotelFacility::query()
            ->where('facility_code', 535)
            ->where('facility_group_code', 70)
            ->firstOrFail();
        $this->assertSame('22.00', $pets->amount);
        $this->assertSame('EUR', trim((string) $pets->currency));
        $this->assertTrue($pets->ind_fee);
        $this->assertSame('UN', $pets->application_type);

        $checkIn = ContentHotelFacility::query()->where('facility_code', 260)->where('facility_group_code', 70)->firstOrFail();
        $this->assertSame('15:00:00', substr((string) $checkIn->time_from, 0, 8));

        $certified = ContentHotelFacility::query()->where('facility_code', 909)->where('facility_group_code', 75)->firstOrFail();
        $this->assertSame('2028-10-22', $certified->date_to?->format('Y-m-d'));
    }

    #[Test]
    public function long_pms_room_codes_are_stored_without_truncation(): void
    {
        $codes = [
            'Classic two Bedroom Apartment (4 adults)',
            'APARTMENT 1 ROOM WITH TERRACE (2ADULTS+2CHILDREN)',
            'SUITE ROOM WITH MOUNTAIN VIEW (2 ADUTLS)',
        ];

        $this->importer()->import($this->contentResult($this->pmsHotel($codes)), 'ENG', 80);

        $stored = ContentHotelRoom::query()->orderBy('room_code')->pluck('pms_room_code')->all();

        $this->assertSame($codes, $stored);
        $this->assertTrue(collect($stored)->every(fn (string $code): bool => strlen($code) > 32));
        $this->assertSame(0, HotelBooking::query()->count());
        $this->assertSame(0, HotelSearch::query()->count());
    }

    #[Test]
    public function a_ranking_of_2147483647_is_stored_exactly(): void
    {
        $this->importer()->import($this->contentResult($this->smallHotel(ranking: 2147483647)), 'ENG');

        $this->assertSame(2147483647, ContentHotel::query()->value('ranking'));
    }

    #[Test]
    public function a_hotels_list_import_does_not_replace_a_hotel_details_snapshot(): void
    {
        $details = $this->importer()->import($this->contentResult($this->detailsShapedHotel()), 'ENG', 712);
        $list = $this->importer()->import($this->contentResult($this->listShapedHotel()), 'ENG', 712, 'list');
        $again = $this->importer()->import($this->contentResult($this->listShapedHotel()), 'ENG', 712, 'list');

        $this->assertSame(ContentImportResult::IMPORTED, $details->status);
        $this->assertSame(ContentImportResult::UNCHANGED, $list->status);
        $this->assertSame(['hotel-details-retained'], $list->conflicts);
        $this->assertSame(ContentImportResult::UNCHANGED, $again->status);
        $this->assertSame($details->hash, $list->hash);
        $this->assertSame($details->hash, ContentHotelSnapshot::query()->value('content_hash'));
        $this->assertSame('details', ContentHotelSnapshot::query()->value('content_origin'));

        $room = ContentHotelRoom::query()->where('room_code', 'SUI.ST')->firstOrFail();
        $this->assertSame('SUITE STANDARD', $room->translations()->value('description'));
        $this->assertSame('ES', ContentHotel::query()->value('country_iso_code'));
        $this->assertSame('A', ContentHotel::query()->firstOrFail()->terminals()->value('terminal_type'));
        $this->assertSame(1, ContentHotel::query()->count());
    }

    #[Test]
    public function repeated_supplier_images_keep_separate_rows_and_gallery_order(): void
    {
        $this->assertSame(0, HotelBooking::query()->count());
        $this->assertSame(0, HotelSearch::query()->count());

        $outcome = $this->importer()->import($this->contentResult($this->duplicateImagesHotel()), 'ENG', 44);
        $second = $this->importer()->import($this->contentResult($this->duplicateImagesHotel()), 'ENG', 44);

        $this->assertSame(ContentImportResult::IMPORTED, $outcome->status);
        $this->assertSame(4, $outcome->images);
        $this->assertSame(ContentImportResult::UNCHANGED, $second->status);

        $hotel = ContentHotel::query()->where('hbx_hotel_code', 44)->firstOrFail();
        $images = ContentHotelImage::query()
            ->where('content_hotel_id', $hotel->id)
            ->orderBy('visual_order')
            ->orderBy('source_position')
            ->get();

        $this->assertSame([0, 1, 2, 3], $images->pluck('source_position')->sort()->values()->all());
        $this->assertSame([1, 2, 3, 0], $images->pluck('source_position')->all());
        $this->assertSame([1, 1, 1, 5], $images->pluck('visual_order')->all());
        $this->assertSame(
            ['SUI.ST', 'DBL.ST', 'DBL.ST', 'DBL.ST'],
            $images->pluck('room_code')->all()
        );
        $this->assertSame($images->pluck('id')->all(), $hotel->images()->pluck('id')->all());
        $this->assertSame(2, $images->where('room_code', 'DBL.ST')->where('visual_order', 1)->where('path', '00/000044/shared.jpg')->count());
        $this->assertSame(0, HotelBooking::query()->count());
        $this->assertSame(0, HotelSearch::query()->count());
        $this->assertSame(0, RateSelection::query()->count());
    }

    #[Test]
    public function the_same_payload_is_unchanged_and_a_content_change_updates_in_place(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 12:00:00'));
        $raw = $this->fixture();
        $first = $this->importer()->import($this->contentResult($raw), 'eng');
        $hotelId = ContentHotel::query()->value('id');

        Carbon::setTestNow(Carbon::parse('2026-09-29 13:00:00'));
        $second = $this->importer()->import($this->contentResult($raw), 'ENG');

        $this->assertSame(ContentImportResult::IMPORTED, $first->status);
        $this->assertSame(ContentImportResult::UNCHANGED, $second->status);
        $this->assertSame(37, ContentHotelRoom::query()->count());
        $this->assertSame($hotelId, ContentHotel::query()->value('id'));
        $this->assertSame(
            '2026-09-29 13:00:00',
            ContentHotelSnapshot::query()->firstOrFail()->content_synced_at?->format('Y-m-d H:i:s')
        );

        $changed = str_replace('"content" : "Alua Suites las Rocas"', '"content" : "Alua Suites Updated"', $raw);
        $third = $this->importer()->import($this->contentResult($changed), 'ENG');

        $this->assertSame(ContentImportResult::UPDATED, $third->status);
        $this->assertNotSame($first->hash, $third->hash);
        $this->assertSame($hotelId, ContentHotel::query()->value('id'));
        $this->assertSame('Alua Suites Updated', ContentHotel::query()->firstOrFail()->translations()->where('language', 'ENG')->value('name'));
        $this->assertSame(37, ContentHotelRoom::query()->count());
        $this->assertSame(1, ContentHotel::query()->count());
    }

    #[Test]
    public function a_non_eng_import_updates_translations_and_reports_structural_conflicts(): void
    {
        $this->importer()->import($this->contentResult($this->fixture()), 'ENG');
        $hotelId = ContentHotel::query()->value('id');
        $imageCount = ContentHotelImage::query()->count();
        $arabic = str_replace('"content" : "Alua Suites las Rocas"', '"content" : "ألوا سويتس"', $this->fixture());
        $translated = $this->importer()->import($this->contentResult($arabic), 'ARA');

        $this->assertSame(ContentImportResult::IMPORTED, $translated->status);
        $this->assertSame([], $translated->conflicts);
        $this->assertSame($imageCount, ContentHotelImage::query()->count());
        $this->assertSame($hotelId, ContentHotel::query()->value('id'));
        $this->assertSame('Alua Suites las Rocas', ContentHotel::query()->firstOrFail()->translations()->where('language', 'ENG')->value('name'));
        $this->assertSame('ألوا سويتس', ContentHotel::query()->firstOrFail()->translations()->where('language', 'ARA')->value('name'));
        $this->assertSame('1 BEDROOM 2 ADULTS', ContentHotelRoom::query()->where('room_code', 'SUI.ST')->firstOrFail()->translations()->where('language', 'ARA')->value('commercial_description'));

        $conflictRaw = str_replace('"ranking" : 5', '"ranking" : 9', $arabic);
        $conflict = $this->importer()->import($this->contentResult($conflictRaw), 'ARA');

        $this->assertSame(ContentImportResult::UPDATED, $conflict->status);
        $this->assertContains('ranking', $conflict->conflicts);
        $this->assertSame(5, ContentHotel::query()->value('ranking'));
        $this->assertSame($imageCount, ContentHotelImage::query()->count());

        try {
            $this->importer()->import($this->contentResult('{"version":"1.0","hotel":{"code":999,"name":{"content":"Other"}}}'), 'ARA');
            $this->fail('Non-ENG import cannot create shared structure.');
        } catch (HbxValidationException $exception) {
            $this->assertSame('CONTENT_ENG_REQUIRED', $exception->supplierCode);
        }

        $this->assertNull(ContentHotel::query()->where('hbx_hotel_code', 999)->first());
    }

    #[Test]
    public function an_unmatched_wildcard_does_not_create_a_room(): void
    {
        $raw = $this->smallHotel(wildcard: 'NO.ROOM');
        $outcome = $this->importer()->import($this->contentResult($raw), 'ENG');

        $this->assertSame(['NO.ROOM'], $outcome->unmatchedWildcards);
        $this->assertSame(2, ContentHotelRoom::query()->count());
        $this->assertNull(ContentHotelRoom::query()->where('room_code', 'NO.ROOM')->first());
        $this->assertSame('1 BEDROOM 2 ADULTS', ContentHotelRoom::query()->where('room_code', 'SUI.ST')->firstOrFail()->translations()->value('commercial_description'));
    }

    #[Test]
    public function a_failed_import_rolls_back_the_transaction(): void
    {
        ContentHotelImage::creating(function (): void {
            throw new RuntimeException('forced import failure');
        });

        try {
            $this->importer()->import($this->contentResult($this->smallHotel()), 'ENG');
            $this->fail('The import should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced import failure', $exception->getMessage());
        }

        $this->assertSame(0, ContentHotel::query()->count());
        $this->assertSame(0, ContentHotelRoom::query()->count());
        $this->assertSame(0, ContentHotelSnapshot::query()->count());
        $this->assertSame(0, ContentHotelImage::query()->count());
    }

    #[Test]
    public function import_does_not_modify_booking_or_availability_tables_or_call_hbx(): void
    {
        $this->importer()->import($this->contentResult($this->fixture()), 'ENG');

        $this->assertSame(0, HotelSearch::query()->count());
        $this->assertSame(0, RateSelection::query()->count());
        $this->assertSame(0, HotelBooking::query()->count());
        $this->assertTrue(Schema::hasColumn('hotel_searches', 'search_fingerprint'));
        $this->assertTrue(Schema::hasColumn('hotel_searches', 'response_payload'));
        $this->assertTrue(Schema::hasColumn('rate_selections', 'valid_until'));
        $this->assertTrue(Schema::hasColumn('rate_selections', 'rate_key'));
        $this->assertFalse(Schema::hasColumn('hotel_searches', 'hbx_hotel_code'));
        $this->assertFalse(Schema::hasColumn('content_hotels', 'rate_key'));
        Http::assertNothingSent();
    }

    #[Test]
    public function the_import_command_prints_a_summary_and_discovery_does_not_store_content(): void
    {
        $payload = $this->smallHotel();
        Http::fake([
            'https://api.test.hotelbeds.com/*' => Http::response($payload, 200),
        ]);

        $this->artisan('hbx:content:hotel', ['hotelCode' => 712, '--language' => 'ENG'])
            ->expectsOutputToContain('HTTP status: 200')
            ->assertSuccessful();

        $this->assertSame(0, ContentHotel::query()->count());

        $this->artisan('hbx:content:hotel', ['hotelCode' => 712, '--language' => 'ENG', '--import' => true])
            ->expectsOutputToContain('Hotel: 712')
            ->expectsOutputToContain('Language: ENG')
                ->expectsOutputToContain('Rooms: 2')
            ->expectsOutputToContain('Images: 1')
            ->expectsOutputToContain('Facilities: 1')
            ->expectsOutputToContain('Snapshot bytes: '.strlen($payload))
            ->expectsOutputToContain('Hash: '.substr((new ContentHotelHasher)->hash($payload), 0, 12))
            ->expectsOutputToContain('Result: IMPORTED')
            ->doesntExpectOutputToContain('HTTP status:')
            ->assertSuccessful();

        $this->artisan('hbx:content:hotel', ['hotelCode' => 712, '--language' => 'ENG', '--import' => true])
            ->expectsOutputToContain('Result: UNCHANGED')
            ->assertSuccessful();

        Http::assertSentCount(3);
    }

    private function importer(): HbxContentHotelImporter
    {
        return app(HbxContentHotelImporter::class);
    }

    private function fixture(): string
    {
        $raw = file_get_contents(dirname(__DIR__).'/Fixtures/content-hotel-712.json');
        $this->assertIsString($raw);

        return $raw;
    }

    private function contentResult(string $raw): HbxResult
    {
        return new HbxResult(
            operation: 'content_hotel_detail',
            method: 'GET',
            endpoint: '/hotel-content-api/1.0/hotels/712/details?language=ENG&useSecondaryLanguage=false',
            httpStatus: 200,
            data: JsonDecimals::decode($raw),
            rawBody: $raw,
            processTime: '25',
            durationMs: 5,
            supplierTimestamp: '2026-09-29 10:00:00.000',
        );
    }

    /**
     * @param  list<string>  $pmsCodes
     */
    private function pmsHotel(array $pmsCodes): string
    {
        $rooms = [];

        foreach ($pmsCodes as $index => $pmsCode) {
            $rooms[] = array_merge($this->room('RM.'.$index, 'RM', (string) $index), [
                'PMSRoomCode' => $pmsCode,
            ]);
        }

        return json_encode([
            'version' => '1.0',
            'hotel' => [
                'code' => 80,
                'name' => ['content' => 'Long PMS Hotel'],
                'rooms' => $rooms,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function detailsShapedHotel(): string
    {
        return json_encode([
            'version' => '1.0',
            'hotel' => [
                'code' => 712,
                'name' => ['content' => 'Details Hotel'],
                'country' => ['code' => 'ES', 'isoCode' => 'ES'],
                'ranking' => 5,
                'rooms' => [
                    array_merge($this->room('SUI.ST', 'SUI', 'ST'), [
                        'description' => 'SUITE STANDARD',
                    ]),
                ],
                'terminals' => [[
                    'terminalCode' => 'PMI',
                    'terminalType' => 'A',
                    'distance' => 63,
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function listShapedHotel(): string
    {
        return json_encode([
            'version' => '1.0',
            'hotel' => [
                'code' => 712,
                'name' => ['content' => 'Details Hotel'],
                'country' => ['code' => 'ES'],
                'ranking' => 5,
                'rooms' => [
                    $this->room('SUI.ST', 'SUI', 'ST'),
                ],
                'terminals' => [[
                    'terminalCode' => 'PMI',
                    'distance' => 63,
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function duplicateImagesHotel(): string
    {
        $image = function (string $roomCode, int $visualOrder): array {
            return [
                'type' => ['code' => 'HAB'],
                'path' => '00/000044/shared.jpg',
                'order' => 1,
                'visualOrder' => $visualOrder,
                'roomCode' => $roomCode,
                'roomType' => 'ROOM',
                'characteristicCode' => 'ST',
            ];
        };

        return json_encode([
            'version' => '1.0',
            'hotel' => [
                'code' => 44,
                'name' => ['content' => 'Duplicate Image Hotel'],
                'rooms' => [
                    $this->room('SUI.ST', 'SUI', 'ST'),
                    $this->room('DBL.ST', 'DBL', 'ST'),
                ],
                'images' => [
                    $image('DBL.ST', 5),
                    $image('SUI.ST', 1),
                    $image('DBL.ST', 1),
                    $image('DBL.ST', 1),
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function room(string $roomCode, string $type, string $characteristic): array
    {
        return [
            'roomCode' => $roomCode,
            'isParentRoom' => false,
            'minPax' => 1,
            'maxPax' => 2,
            'maxAdults' => 2,
            'maxChildren' => 0,
            'minAdults' => 1,
            'type' => ['code' => $type],
            'characteristic' => ['code' => $characteristic],
        ];
    }

    private function smallHotel(string $wildcard = 'SUI.ST', int $ranking = 5): string
    {
        return json_encode([
            'version' => '1.0',
            'auditData' => [
                'processTime' => '10',
                'timestamp' => '2026-09-29 10:00:00.000',
                'requestHost' => 'hidden.example',
            ],
            'hotel' => [
                'code' => 712,
                'name' => ['content' => 'Import Command Hotel'],
                'ranking' => $ranking,
                'rooms' => [[
                    'roomCode' => 'SUI.ST',
                    'isParentRoom' => false,
                    'minPax' => 1,
                    'maxPax' => 3,
                    'maxAdults' => 2,
                    'maxChildren' => 1,
                    'minAdults' => 1,
                    'description' => 'SUITE STANDARD',
                    'type' => ['code' => 'SUI', 'description' => ['content' => 'SUITE']],
                    'characteristic' => ['code' => 'ST', 'description' => ['content' => 'STANDARD']],
                    'PMSRoomCode' => 'SUITE',
                ], [
                    'roomCode' => 'SUI.ST-2',
                    'isParentRoom' => false,
                    'minPax' => 1,
                    'maxPax' => 4,
                    'maxAdults' => 2,
                    'maxChildren' => 2,
                    'minAdults' => 1,
                    'description' => 'SUITE STANDARD',
                    'type' => ['code' => 'SUI'],
                    'characteristic' => ['code' => 'ST-2'],
                    'PMSRoomCode' => 'SUITE',
                ]],
                'facilities' => [[
                    'facilityCode' => 535,
                    'facilityGroupCode' => 70,
                    'description' => ['content' => 'Small pets allowed'],
                    'order' => 1,
                    'voucher' => false,
                    'indFee' => true,
                    'amount' => 22,
                    'currency' => 'EUR',
                    'applicationType' => 'UN',
                    'timeFrom' => '15:00:00',
                    'dateTo' => '2028-10-22',
                ]],
                'images' => [[
                    'type' => ['code' => 'GEN', 'description' => ['content' => 'General view']],
                    'path' => '00/000712/hero.jpg',
                    'order' => 1,
                    'visualOrder' => 1,
                ]],
                'wildcards' => [[
                    'roomType' => 'SUI.ST',
                    'roomCode' => 'SUI',
                    'characteristicCode' => 'ST',
                    'hotelRoomDescription' => ['content' => '1 BEDROOM 2 ADULTS'],
                ], [
                    'roomType' => $wildcard,
                    'roomCode' => 'NO',
                    'characteristicCode' => 'ROOM',
                    'hotelRoomDescription' => ['content' => 'Missing room'],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
