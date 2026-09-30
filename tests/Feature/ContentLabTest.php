<?php

namespace Tests\Feature;

use App\Models\ContentHotel;
use App\Models\ContentHotelFacility;
use App\Models\ContentHotelImage;
use App\Models\ContentHotelRoom;
use App\Models\ContentHotelRoomTranslation;
use App\Models\ContentHotelSnapshot;
use App\Models\ContentHotelTerminal;
use App\Models\ContentHotelTranslation;
use App\Services\HBX\HbxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentLabTest extends TestCase
{
    use RefreshDatabase;

    private bool $hbxClientResolved = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->resolving(HbxClient::class, function (): void {
            $this->hbxClientResolved = true;
        });
    }

    protected function tearDown(): void
    {
        $this->assertFalse($this->hbxClientResolved);
        Http::assertNothingSent();

        parent::tearDown();
    }

    #[Test]
    public function the_hotel_list_reads_mysql_and_searches_by_code_and_name(): void
    {
        $this->seedHotel(712, 'Alua Suites', 'details', 'Palma', 'PMI');
        $this->seedHotel(20, 'Other Inn', 'list', 'Barcelona', 'BCN');

        $list = $this->get(route('content.hotels.index'));
        $list->assertOk();
        $list->assertSee('Alua Suites');
        $list->assertSee('Other Inn');
        $list->assertSee('does not call HBX');
        $list->assertSee('Content', false);

        $hotel = $list->viewData('hotels')->first();
        $this->assertFalse($hotel->relationLoaded('rooms'));
        $this->assertFalse($hotel->relationLoaded('images'));
        $this->assertFalse($hotel->relationLoaded('facilities'));
        $this->assertArrayNotHasKey('raw_payload', $hotel->snapshots->first()->getAttributes());

        $this->get(route('content.hotels.index', ['q' => '712']))
            ->assertOk()
            ->assertSee('Alua Suites')
            ->assertDontSee('Other Inn');

        $this->get(route('content.hotels.index', ['q' => 'Other']))
            ->assertOk()
            ->assertSee('Other Inn')
            ->assertDontSee('Alua Suites');

        $this->get(route('content.index'))->assertRedirect(route('content.hotels.index'));
        Http::assertNothingSent();
    }

    #[Test]
    public function hotel_detail_resolves_by_hbx_code_and_keeps_the_snapshot_off_the_page(): void
    {
        $this->seedHotel(712, 'Alua Suites', 'details', 'Palma', 'PMI', 'SNAPSHOT-SECRET-712', [
            'description' => 'SUITE STANDARD',
            'terminal_type' => 'A',
            'terminal_code' => 'PMI',
            'image' => '00/000712/a.jpg',
            'unsafe_image' => 'javascript:alert(1)',
        ]);

        $page = $this->get(route('content.hotels.show', ['hotelCode' => 712]));
        $page->assertOk();
        $page->assertSee('Origin: details');
        $page->assertSee('SUITE STANDARD');
        $page->assertSee('PMI');
        $page->assertSee('photos.hotelbeds.com/giata/small/00/000712/a.jpg', false);
        $page->assertDontSee('src="javascript:', false);
        $page->assertDontSee('SNAPSHOT-SECRET-712');
        $this->assertArrayNotHasKey('raw_payload', $page->viewData('hotel')->snapshots->first()->getAttributes());

        $raw = $this->get(route('developer.content.snapshot', ['hotelCode' => 712]));
        $raw->assertOk();
        $raw->assertSee('SNAPSHOT-SECRET-712');
        $raw->assertSee('loaded only on this page');
        $this->assertStringStartsWith('/developer/', parse_url(route('developer.content.snapshot', ['hotelCode' => 712]), PHP_URL_PATH));

        $this->get('/content/hotels/712/snapshot')->assertNotFound();
        $this->get(route('content.hotels.show', ['hotelCode' => 999999]))->assertNotFound();
        Http::assertNothingSent();
    }

    #[Test]
    public function a_list_origin_hotel_renders_without_details_only_fields(): void
    {
        $this->seedHotel(11, 'List Hotel', 'list', 'Madrid', 'MAD', 'SNAPSHOT-SECRET-LIST', [
            'terminal_type' => null,
            'terminal_code' => 'BCN',
        ]);

        $page = $this->get(route('content.hotels.show', ['hotelCode' => 11]));
        $page->assertOk();
        $page->assertSee('Origin: list');
        $page->assertSee('BCN');
        $page->assertDontSee('SUITE STANDARD');
        $page->assertDontSee('SNAPSHOT-SECRET-LIST');
        Http::assertNothingSent();
    }

    #[Test]
    public function the_hotel_list_is_paginated(): void
    {
        for ($code = 1; $code <= 26; $code++) {
            $this->seedHotel($code, 'Paged Hotel '.$code, 'list', 'City', 'PMI');
        }

        $this->get(route('content.hotels.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Paged Hotel 26')
            ->assertDontSee('Paged Hotel 1');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function seedHotel(
        int $code,
        string $name,
        string $origin,
        string $city,
        string $destination,
        string $secret = 'snapshot-body',
        array $extra = [],
    ): ContentHotel {
        $hotel = ContentHotel::query()->create([
            'hbx_hotel_code' => $code,
            'country_code' => 'ES',
            'country_iso_code' => $origin === 'details' ? 'ES' : null,
            'destination_code' => $destination,
            'category_code' => '4EST',
            'ranking' => 5,
            'postal_code' => '07001',
            'email' => 'hotel@example.test',
            'web' => 'https://example.test',
            'license' => 'L-1',
            'supplier_last_update' => '2026-09-28',
            'latitude' => '39.36257000',
            'longitude' => '2.75000000',
        ]);

        ContentHotelTranslation::query()->create([
            'content_hotel_id' => $hotel->id,
            'language' => 'ENG',
            'name' => $name,
            'description' => 'A stored description',
            'address_line' => '1 Sea Road',
            'city' => $city,
        ]);

        $raw = '{"secret":"'.$secret.'"}';
        ContentHotelSnapshot::query()->create([
            'content_hotel_id' => $hotel->id,
            'language' => 'ENG',
            'raw_payload' => $raw,
            'content_hash' => hash('sha256', $raw),
            'payload_bytes' => strlen($raw),
            'content_origin' => $origin,
            'content_synced_at' => now(),
        ]);

        if (array_key_exists('terminal_code', $extra)) {
            ContentHotelTerminal::query()->create([
                'content_hotel_id' => $hotel->id,
                'terminal_code' => $extra['terminal_code'],
                'terminal_type' => $extra['terminal_type'],
                'distance' => 63,
            ]);
        }

        if (isset($extra['description'])) {
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
                'pms_room_code' => 'SUITE',
            ]);
            ContentHotelRoomTranslation::query()->create([
                'content_hotel_room_id' => $room->id,
                'language' => 'ENG',
                'description' => $extra['description'],
                'commercial_description' => '1 BEDROOM 2 ADULTS',
            ]);
            ContentHotelFacility::query()->create([
                'content_hotel_id' => $hotel->id,
                'facility_code' => 535,
                'facility_group_code' => 70,
                'sort_order' => 1,
                'number_value' => 22,
                'ind_fee' => true,
                'ind_yes_or_no' => true,
                'amount' => '22.00',
                'currency' => 'EUR',
                'application_type' => 'UN',
                'time_from' => '15:00:00',
            ]);
        }

        if (isset($extra['image'])) {
            ContentHotelImage::query()->create([
                'content_hotel_id' => $hotel->id,
                'image_type_code' => 'HAB',
                'path' => $extra['image'],
                'room_code' => 'SUI.ST',
                'supplier_order' => 1,
                'visual_order' => 1,
                'source_position' => 0,
            ]);
        }

        if (isset($extra['unsafe_image'])) {
            ContentHotelImage::query()->create([
                'content_hotel_id' => $hotel->id,
                'image_type_code' => 'HAB',
                'path' => $extra['unsafe_image'],
                'supplier_order' => 2,
                'visual_order' => 2,
                'source_position' => 1,
            ]);
        }

        return $hotel;
    }
}
