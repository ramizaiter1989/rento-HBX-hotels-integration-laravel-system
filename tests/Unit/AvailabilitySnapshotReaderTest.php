<?php

namespace Tests\Unit;

use App\Models\HotelSearch;
use App\Services\HBX\AvailabilitySnapshotReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AvailabilitySnapshotReaderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function summaries_stay_bounded_when_the_hotel_list_precedes_the_total(): void
    {
        $search = $this->search($this->payload(25, hotelsFirst: true));
        $reader = app(AvailabilitySnapshotReader::class);

        $page = $reader->getHotelSummaries($search, 1, 20);

        $this->assertSame(25, $page['total']);
        $this->assertCount(20, $page['hotels']);
        $this->assertSame('Hotel #001', $page['hotels'][0]['name']);
        $this->assertSame('121.50', $page['hotels'][0]['min_rate']);
        $this->assertSame('Hotel #020', $page['hotels'][19]['name']);
        $this->assertArrayNotHasKey('rooms', $page['hotels'][0]);
    }

    #[Test]
    public function a_rate_key_with_reserved_characters_is_returned_unchanged(): void
    {
        $rateKey = 'RATE/KEY"EXACT\\TAIL';
        $search = $this->search($this->payload(2, rateKey: $rateKey));
        $match = app(AvailabilitySnapshotReader::class)->findRate($search, $rateKey);

        $this->assertNotNull($match);
        $this->assertSame($rateKey, $match['rate']['rateKey']);
        $this->assertSame('SUI.ST', $match['room']['code']);
    }

    private function search(string $payload): HotelSearch
    {
        return HotelSearch::query()->create([
            'destination_code' => 'PMI',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-11',
            'rooms_count' => 1,
            'adults_count' => 2,
            'children_count' => 0,
            'request_payload' => '{}',
            'response_payload' => $payload,
            'hotels_returned' => 0,
        ]);
    }

    private function payload(int $hotels, bool $hotelsFirst = false, ?string $rateKey = null): string
    {
        $list = [];

        for ($index = 1; $index <= $hotels; $index++) {
            $key = $index === 1 && $rateKey !== null
                ? $rateKey
                : 'RATEKEY-H'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);

            $list[] = [
                'code' => 1000 + $index,
                'name' => 'Hotel #'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'categoryName' => '4 STARS',
                'destinationCode' => 'PMI',
                'destinationName' => 'Majorca',
                'zoneName' => 'Cala',
                'minRate' => '121.50',
                'maxRate' => '180.00',
                'currency' => 'EUR',
                'rooms' => [[
                    'code' => 'SUI.ST',
                    'name' => 'Room #'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                    'rates' => [[
                        'rateKey' => $key,
                        'rateType' => 'BOOKABLE',
                        'net' => '121.50',
                    ]],
                ]],
            ];
        }

        $node = [
            'checkIn' => '2026-10-10',
            'checkOut' => '2026-10-11',
            'total' => $hotels,
            'hotels' => $list,
        ];

        if ($hotelsFirst) {
            $node = [
                'hotels' => $list,
                'checkIn' => '2026-10-10',
                'checkOut' => '2026-10-11',
                'total' => $hotels,
            ];
        }

        return json_encode([
            'auditData' => ['processTime' => 'RAW-ONLY-TOKEN'],
            'hotels' => $node,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
