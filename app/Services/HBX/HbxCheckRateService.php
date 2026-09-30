<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\Exceptions\HBX\HbxValidationException;
use App\Models\HotelSearch;
use App\Models\RateSelection;
use App\Support\JsonDecimals;
use App\Support\PositiveConfigInt;
use Illuminate\Support\Carbon;

final class HbxCheckRateService
{
    public function __construct(
        private readonly HbxClient $client,
        private readonly AvailabilityResultReader $reader,
        private readonly AvailabilitySnapshotReader $snapshots,
    ) {}

    public function check(HotelSearch $search, string $rateKey): RateSelection
    {
        if (! $search->isFresh()) {
            throw new HbxValidationException(
                'This availability snapshot has expired. Run a new hotel search before selecting or checking this rate.',
                'AVAILABILITY_SNAPSHOT_EXPIRED',
                null,
                [],
                'checkrate'
            );
        }

        $match = $this->snapshots->findRate($search, $rateKey);
        $selection = $this->existingSelection($search, $rateKey);

        if ($match === null && $selection === null) {
            throw new HbxValidationException(
                'That rateKey was not found on this availability snapshot.',
                'INVALID_DATA',
                null,
                [],
                'checkrate'
            );
        }

        $keyToSend = $selection?->rate_key ?? $match['rate']['rateKey'];

        $result = $this->client->post(
            (string) config('hbx.endpoints.checkrates'),
            ['rooms' => [['rateKey' => $keyToSend]]],
            'checkrate',
            ['hotel_search_id' => $search->id]
        );

        $refreshed = $this->reader->firstCheckRate($result->data);

        if ($refreshed === null) {
            throw new HbxValidationException(
                'CheckRate did not return a usable rate.',
                'INVALID_DATA',
                $result->httpStatus,
                $result->data,
                'checkrate'
            );
        }

        $presented = $this->reader->rate($refreshed['rate']);
        $hotel = $refreshed['hotel'];
        $room = $refreshed['room'];
        $source = $match ?? [
            'hotel' => $hotel,
            'room' => $room,
            'rate' => ['rateKey' => $selection->original_rate_key, 'rateType' => $selection->original_rate_type],
        ];

        $attributes = $this->attributes($search, $source, $presented, $hotel, $room, $result->rawBody, $selection);

        if ($selection) {
            $selection->fill($attributes);
            $selection->save();

            return $selection->refresh();
        }

        return RateSelection::query()->create($attributes);
    }

    private function existingSelection(HotelSearch $search, string $rateKey): ?RateSelection
    {
        return $search->rateSelections()
            ->where(function ($query) use ($rateKey): void {
                $query->where('original_rate_key', $rateKey)->orWhere('rate_key', $rateKey);
            })
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $presented
     * @param  array<string, mixed>  $hotel
     * @param  array<string, mixed>  $room
     * @return array<string, mixed>
     */
    private function attributes(
        HotelSearch $search,
        array $source,
        array $presented,
        array $hotel,
        array $room,
        string $rawBody,
        ?RateSelection $selection,
    ): array {
        $sourceHotel = $source['hotel'];
        $sourceRoom = $source['room'];
        $sourceRate = $source['rate'];

        return [
            'hotel_search_id' => $search->id,
            'hotel_code' => isset($hotel['code']) ? (string) $hotel['code'] : (isset($sourceHotel['code']) ? (string) $sourceHotel['code'] : null),
            'hotel_name' => $hotel['name'] ?? $sourceHotel['name'] ?? null,
            'category_name' => $hotel['categoryName'] ?? $sourceHotel['categoryName'] ?? null,
            'destination_code' => $hotel['destinationCode'] ?? $sourceHotel['destinationCode'] ?? null,
            'destination_name' => $hotel['destinationName'] ?? $sourceHotel['destinationName'] ?? null,
            'zone_name' => $hotel['zoneName'] ?? $sourceHotel['zoneName'] ?? null,
            'latitude' => isset($hotel['latitude']) ? (string) $hotel['latitude'] : (isset($sourceHotel['latitude']) ? (string) $sourceHotel['latitude'] : null),
            'longitude' => isset($hotel['longitude']) ? (string) $hotel['longitude'] : (isset($sourceHotel['longitude']) ? (string) $sourceHotel['longitude'] : null),
            'room_code' => $room['code'] ?? $sourceRoom['code'] ?? null,
            'room_name' => $room['name'] ?? $sourceRoom['name'] ?? null,
            'original_rate_key' => $selection->original_rate_key ?? $sourceRate['rateKey'],
            'rate_key' => $presented['rate_key'],
            'original_rate_type' => $selection->original_rate_type ?? ($sourceRate['rateType'] ?? 'BOOKABLE'),
            'rate_type' => $presented['rate_type'] ?? $sourceRate['rateType'] ?? 'BOOKABLE',
            'rate_class' => $presented['rate_class'],
            'net' => $presented['net'],
            'currency' => $hotel['currency'] ?? $sourceHotel['currency'] ?? null,
            'allotment' => $presented['allotment'],
            'board_code' => $presented['board_code'],
            'board_name' => $presented['board_name'],
            'payment_type' => $presented['payment_type'],
            'packaging' => $presented['packaging'],
            'payment_data_required' => array_key_exists('paymentDataRequired', $hotel) ? (bool) $hotel['paymentDataRequired'] : null,
            'rate_comments' => $presented['rate_comments'],
            'taxes' => JsonDecimals::encode($presented['taxes']),
            'cancellation_policies' => JsonDecimals::encode($presented['cancellation_policies']),
            'promotions' => JsonDecimals::encode($presented['promotions']),
            'checkrate_response' => $rawBody,
            'checkrate_completed_at' => Carbon::now(),
            'valid_until' => Carbon::now()->addSeconds(
                PositiveConfigInt::from(config('hbx.availability.checked_rate_ttl_seconds'), 300)
            ),
        ];
    }
}
