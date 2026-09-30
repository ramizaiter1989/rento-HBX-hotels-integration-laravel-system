<?php

namespace Tests\Feature;

use App\Models\HotelSearch;
use App\Models\RateSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\HbxFixture;
use Tests\TestCase;

class AvailabilityResultsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_large_availability_snapshot_is_paged_without_embedding_rooms_or_raw_json(): void
    {
        $firstRateKey = 'RATEKEY-H001-R1-I1-OPAQUE';
        $laterRateKey = 'RATEKEY-H021-R1-I1-OPAQUE';
        $payload = $this->largeAvailability();

        Http::fake([
            'https://api.test.hotelbeds.com/hotel-api/1.0/hotels' => Http::response($payload, 200),
            'https://api.test.hotelbeds.com/hotel-api/1.0/checkrates' => Http::response(HbxFixture::checkRate($firstRateKey), 200),
        ]);

        $this->post(route('hotels.search.store'), [
            'check_in' => now()->addDays(20)->toDateString(),
            'check_out' => now()->addDays(21)->toDateString(),
            'rooms' => 1,
            'adults' => 2,
            'children' => 0,
            'destination_code' => 'PMI',
            'hotel_code' => '',
        ])->assertRedirect();

        $search = HotelSearch::query()->firstOrFail();
        $this->assertSame(45, $search->hotels_returned);
        $this->assertStringContainsString('RAW-ONLY-TOKEN', (string) $search->response_payload);
        $this->assertStringContainsString($firstRateKey, (string) $search->response_payload);

        $page = $this->get(route('hotels.results', $search));
        $page->assertOk();
        $page->assertSee('Hotel #001');
        $page->assertSee('Hotel #020');
        $page->assertSee('Show rooms');
        $page->assertSee('page=2');
        $page->assertDontSee('Hotel #021');
        $page->assertDontSee('Hotel #045');
        $page->assertDontSee('Room #001');
        $page->assertDontSee($firstRateKey);
        $page->assertDontSee($laterRateKey);
        $page->assertDontSee('ROOM-TOKEN-HOTEL-021');
        $page->assertDontSee('RAW-ONLY-TOKEN');
        $page->assertDontSee('Content API hotels');

        $second = $this->get(route('hotels.results', ['search' => $search, 'page' => 2]));
        $second->assertOk();
        $second->assertSee('Hotel #021');
        $second->assertSee('Hotel #040');
        $second->assertDontSee('Hotel #001');
        $second->assertDontSee('Hotel #041');
        $second->assertDontSee($laterRateKey);
        $second->assertDontSee('RAW-ONLY-TOKEN');

        $rooms = $this->get(route('hotels.rooms', ['search' => $search, 'hotelCode' => 1001]), [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $rooms->assertOk();
        $rooms->assertSee('Room #001-1');
        $rooms->assertSee($firstRateKey);
        $rooms->assertSee('2026-10-08T23:59:00+02:00');
        $rooms->assertSee('City Tax');
        $rooms->assertDontSee($laterRateKey);
        $rooms->assertDontSee('ROOM-TOKEN-HOTEL-021');
        $rooms->assertDontSee('RAW-ONLY-TOKEN');

        $laterRooms = $this->get(route('hotels.rooms', ['search' => $search, 'hotelCode' => 1021]));
        $laterRooms->assertOk();
        $laterRooms->assertSee($laterRateKey);
        $laterRooms->assertSee('ROOM-TOKEN-HOTEL-021');
        $laterRooms->assertDontSee($firstRateKey);

        $raw = $this->get(route('developer.search-raw', $search));
        $raw->assertOk();
        $raw->assertSee('RAW-ONLY-TOKEN');
        $raw->assertSee($firstRateKey);
        $raw->assertSee('ROOM-TOKEN-HOTEL-021');

        $this->post(route('hotels.check-rate', $search), ['rate_key' => $firstRateKey])->assertRedirect();
        $this->assertSame($firstRateKey, RateSelection::query()->value('original_rate_key'));
        $this->assertSame($firstRateKey, RateSelection::query()->value('rate_key'));
    }

    private function largeAvailability(): string
    {
        $hotels = [];

        for ($index = 1; $index <= 45; $index++) {
            $label = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $rooms = [];

            for ($room = 1; $room <= 2; $room++) {
                $rates = [];

                for ($rate = 1; $rate <= 2; $rate++) {
                    $rates[] = [
                        'rateKey' => "RATEKEY-H{$label}-R{$room}-I{$rate}-OPAQUE",
                        'rateClass' => 'NOR',
                        'rateType' => 'BOOKABLE',
                        'net' => '121.18',
                        'allotment' => 40 + $rate,
                        'paymentType' => 'AT_WEB',
                        'packaging' => false,
                        'boardCode' => 'BB',
                        'boardName' => 'BED AND BREAKFAST',
                        'cancellationPolicies' => [[
                            'amount' => '118.13',
                            'from' => '2026-10-08T23:59:00+02:00',
                        ]],
                        'taxes' => [
                            'taxes' => [[
                                'included' => false,
                                'amount' => '4.40',
                                'currency' => 'EUR',
                                'subType' => 'City Tax',
                            ]],
                        ],
                        'rateComments' => $index === 21 && $room === 1 && $rate === 1
                            ? 'ROOM-TOKEN-HOTEL-021'
                            : 'Tax payable on arrival.',
                    ];
                }

                $rooms[] = [
                    'code' => 'RM'.$room,
                    'name' => "Room #{$label}-{$room}",
                    'rates' => $rates,
                ];
            }

            $hotels[] = [
                'code' => 1000 + $index,
                'name' => "Hotel #{$label}",
                'categoryName' => '4 STARS',
                'destinationCode' => 'PMI',
                'destinationName' => 'Majorca',
                'zoneName' => 'Palma',
                'minRate' => '121.18',
                'maxRate' => '180.00',
                'currency' => 'EUR',
                'rooms' => $rooms,
            ];
        }

        return json_encode([
            'auditData' => ['processTime' => 'RAW-ONLY-TOKEN', 'timestamp' => '2026-09-28 12:00:00.000'],
            'hotels' => [
                'hotels' => $hotels,
                'checkIn' => '2026-10-10',
                'checkOut' => '2026-10-11',
                'total' => 45,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
