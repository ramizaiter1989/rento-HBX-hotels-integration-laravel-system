<?php

namespace Tests\Unit;

use App\DTOs\HBX\AvailabilityQuery;
use App\Exceptions\HBX\HbxValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AvailabilityQueryTest extends TestCase
{
    #[Test]
    public function destination_search_uses_only_the_destination_filter(): void
    {
        $payload = $this->availabilityQuery(['destination_code' => 'pmi', 'hotel_code' => ''])->toPayload();

        $this->assertSame('PMI', $payload['destination']['code']);
        $this->assertArrayNotHasKey('hotels', $payload);
        $this->assertSame(1, $payload['occupancies'][0]['rooms']);
        $this->assertSame(2, $payload['occupancies'][0]['adults']);
    }

    #[Test]
    public function hotel_search_uses_only_hotel_codes(): void
    {
        $payload = $this->availabilityQuery(['destination_code' => '', 'hotel_code' => '712, 44'])->toPayload();

        $this->assertSame([712, 44], $payload['hotels']['hotel']);
        $this->assertArrayNotHasKey('destination', $payload);
    }

    #[Test]
    public function conflicting_filters_are_rejected_before_a_request_exists(): void
    {
        $this->expectException(HbxValidationException::class);

        $this->availabilityQuery(['destination_code' => 'PMI', 'hotel_code' => '712'])->toPayload();
    }

    private function availabilityQuery(array $overrides): AvailabilityQuery
    {
        return AvailabilityQuery::fromInput(array_merge([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-11',
            'rooms' => 1,
            'adults' => 2,
            'children' => 0,
            'child_ages' => [],
            'destination_code' => 'PMI',
            'hotel_code' => '',
        ], $overrides));
    }
}
