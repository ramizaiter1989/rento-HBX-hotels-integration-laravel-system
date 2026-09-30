<?php

namespace Tests\Unit;

use App\DTOs\HBX\AvailabilityQuery;
use App\Support\PositiveConfigInt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AvailabilityFingerprintTest extends TestCase
{
    #[Test]
    public function identical_normalized_destination_queries_share_a_fingerprint(): void
    {
        $left = AvailabilityQuery::fromInput($this->input([
            'destination_code' => ' pmi ',
            'rooms' => '1',
            'adults' => '2',
        ]));
        $right = AvailabilityQuery::fromInput($this->input([
            'destination_code' => 'PMI',
            'rooms' => 1,
            'adults' => 2,
        ]));

        $this->assertSame($left->fingerprint('test'), $right->fingerprint('test'));
    }

    #[Test]
    public function hotel_code_order_and_duplicates_do_not_change_the_fingerprint(): void
    {
        $ordered = AvailabilityQuery::fromInput($this->input([
            'destination_code' => '',
            'hotel_code' => '712, 100',
        ]));
        $reversed = AvailabilityQuery::fromInput($this->input([
            'destination_code' => '',
            'hotel_code' => '100,712,100',
        ]));

        $this->assertSame($ordered->fingerprint('test'), $reversed->fingerprint('test'));
        $this->assertNotSame(
            $ordered->fingerprint('test'),
            $this->availabilityQuery(destinationCode: 'PMI', hotelCodes: [])->fingerprint('test'),
        );
    }

    #[Test]
    public function dates_occupancy_and_child_ages_change_the_fingerprint(): void
    {
        $base = $this->availabilityQuery();

        $this->assertNotSame($base->fingerprint('test'), $this->availabilityQuery(checkOut: '2026-10-12')->fingerprint('test'));
        $this->assertNotSame($base->fingerprint('test'), $this->availabilityQuery(adults: 3)->fingerprint('test'));
        $this->assertNotSame(
            $this->availabilityQuery(children: 1, childAges: [5])->fingerprint('test'),
            $this->availabilityQuery(children: 1, childAges: [8])->fingerprint('test'),
        );
        $this->assertNotSame(
            $this->availabilityQuery(children: 2, childAges: [5, 8])->fingerprint('test'),
            $this->availabilityQuery(children: 2, childAges: [8, 5])->fingerprint('test'),
        );
    }

    #[Test]
    public function the_hbx_environment_is_part_of_the_cache_identity(): void
    {
        $query = $this->availabilityQuery();

        $this->assertSame($query->fingerprint(), $query->fingerprint('test'));
        $this->assertNotSame($query->fingerprint('test'), $query->fingerprint('production'));
        $this->assertSame($query->fingerprint('TEST'), $query->fingerprint('test'));

        config(['hbx.environment' => 'production']);

        $this->assertSame($query->fingerprint(), $query->fingerprint('production'));
    }

    #[Test]
    public function destination_and_hotel_code_searches_do_not_collide(): void
    {
        $destination = $this->availabilityQuery(destinationCode: '712', hotelCodes: []);
        $hotels = $this->availabilityQuery(destinationCode: null, hotelCodes: [712]);

        $this->assertNotSame($destination->fingerprint('test'), $hotels->fingerprint('test'));
    }

    #[Test]
    public function non_positive_policy_values_fall_back_to_the_default(): void
    {
        $this->assertSame(60, PositiveConfigInt::from(0, 60));
        $this->assertSame(60, PositiveConfigInt::from(-5, 60));
        $this->assertSame(60, PositiveConfigInt::from('0', 60));
        $this->assertSame(60, PositiveConfigInt::from('', 60));
        $this->assertSame(7, PositiveConfigInt::from('nope', 7));
        $this->assertSame(300, PositiveConfigInt::from('300', 60));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-11',
            'rooms' => 1,
            'adults' => 2,
            'children' => 0,
            'destination_code' => 'PMI',
            'hotel_code' => '',
        ], $overrides);
    }

    /**
     * @param  list<int>  $hotelCodes
     * @param  list<int>  $childAges
     */
    private function availabilityQuery(
        string $checkIn = '2026-10-10',
        string $checkOut = '2026-10-11',
        int $rooms = 1,
        int $adults = 2,
        int $children = 0,
        array $childAges = [],
        ?string $destinationCode = 'PMI',
        array $hotelCodes = [],
    ): AvailabilityQuery {
        return new AvailabilityQuery(
            checkIn: $checkIn,
            checkOut: $checkOut,
            rooms: $rooms,
            adults: $adults,
            children: $children,
            childAges: $childAges,
            destinationCode: $destinationCode,
            hotelCodes: $hotelCodes,
        );
    }
}
