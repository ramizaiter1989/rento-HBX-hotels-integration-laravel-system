<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\Exceptions\HBX\HbxValidationException;
use App\Models\HotelSearch;
use App\Models\RateSelection;
use App\Support\JsonDecimals;

final class RateSelectionService
{
    public function __construct(
        private readonly AvailabilityResultReader $reader,
        private readonly AvailabilitySnapshotReader $snapshots,
    ) {}

    public function selectFromAvailability(HotelSearch $search, string $rateKey): RateSelection
    {
        if (! $search->isFresh()) {
            throw new HbxValidationException(
                'This availability snapshot has expired. Run a new hotel search before selecting or checking this rate.',
                'AVAILABILITY_SNAPSHOT_EXPIRED',
                null,
                [],
                'availability'
            );
        }

        $existing = $search->rateSelections()
            ->where(function ($query) use ($rateKey): void {
                $query->where('original_rate_key', $rateKey)->orWhere('rate_key', $rateKey);
            })
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $match = $this->snapshots->findRate($search, $rateKey);

        if ($match === null) {
            throw new HbxValidationException(
                'That rateKey was not found on this availability snapshot.',
                'INVALID_DATA',
                null,
                [],
                'availability'
            );
        }

        $presented = $this->reader->rate($match['rate']);
        $hotel = $match['hotel'];
        $room = $match['room'];

        return RateSelection::query()->create([
            'hotel_search_id' => $search->id,
            'hotel_code' => isset($hotel['code']) ? (string) $hotel['code'] : null,
            'hotel_name' => $hotel['name'] ?? null,
            'category_name' => $hotel['categoryName'] ?? null,
            'destination_code' => $hotel['destinationCode'] ?? null,
            'destination_name' => $hotel['destinationName'] ?? null,
            'zone_name' => $hotel['zoneName'] ?? null,
            'latitude' => isset($hotel['latitude']) ? (string) $hotel['latitude'] : null,
            'longitude' => isset($hotel['longitude']) ? (string) $hotel['longitude'] : null,
            'room_code' => $room['code'] ?? null,
            'room_name' => $room['name'] ?? null,
            'original_rate_key' => $presented['rate_key'],
            'rate_key' => $presented['rate_key'],
            'original_rate_type' => $presented['rate_type'] ?? 'BOOKABLE',
            'rate_type' => $presented['rate_type'] ?? 'BOOKABLE',
            'rate_class' => $presented['rate_class'],
            'net' => $presented['net'],
            'currency' => $hotel['currency'] ?? null,
            'allotment' => $presented['allotment'],
            'board_code' => $presented['board_code'],
            'board_name' => $presented['board_name'],
            'payment_type' => $presented['payment_type'],
            'packaging' => $presented['packaging'],
            'payment_data_required' => null,
            'rate_comments' => $presented['rate_comments'],
            'taxes' => JsonDecimals::encode($presented['taxes']),
            'cancellation_policies' => JsonDecimals::encode($presented['cancellation_policies']),
            'promotions' => JsonDecimals::encode($presented['promotions']),
            'valid_until' => $search->expires_at,
        ]);
    }
}
