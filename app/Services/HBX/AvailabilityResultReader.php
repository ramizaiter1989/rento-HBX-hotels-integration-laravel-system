<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\Support\DecimalString;
use App\Support\JsonDecimals;

final class AvailabilityResultReader
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function present(array $data): array
    {
        $hotelsNode = is_array($data['hotels'] ?? null) ? $data['hotels'] : [];
        $hotels = [];

        foreach ($hotelsNode['hotels'] ?? [] as $hotel) {
            if (! is_array($hotel)) {
                continue;
            }

            $rooms = [];

            foreach ($hotel['rooms'] ?? [] as $room) {
                if (! is_array($room)) {
                    continue;
                }

                $rates = [];

                foreach ($room['rates'] ?? [] as $rate) {
                    if (! is_array($rate) || ! isset($rate['rateKey']) || ! is_string($rate['rateKey'])) {
                        continue;
                    }

                    $rates[] = $this->rate($rate);
                }

                $rooms[] = [
                    'code' => $room['code'] ?? null,
                    'name' => $room['name'] ?? null,
                    'rates' => $rates,
                ];
            }

            $hotels[] = [
                'code' => $hotel['code'] ?? null,
                'name' => $hotel['name'] ?? null,
                'category' => $hotel['categoryName'] ?? $hotel['categoryCode'] ?? null,
                'destination_code' => $hotel['destinationCode'] ?? null,
                'destination_name' => $hotel['destinationName'] ?? null,
                'zone' => $hotel['zoneName'] ?? null,
                'latitude' => isset($hotel['latitude']) ? (string) $hotel['latitude'] : null,
                'longitude' => isset($hotel['longitude']) ? (string) $hotel['longitude'] : null,
                'min_rate' => DecimalString::from($hotel['minRate'] ?? null),
                'max_rate' => DecimalString::from($hotel['maxRate'] ?? null),
                'currency' => $hotel['currency'] ?? null,
                'rooms' => $rooms,
            ];
        }

        return [
            'check_in' => $hotelsNode['checkIn'] ?? null,
            'check_out' => $hotelsNode['checkOut'] ?? null,
            'total' => (int) ($hotelsNode['total'] ?? count($hotels)),
            'hotels' => $hotels,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{hotel: array<string, mixed>, room: array<string, mixed>, rate: array<string, mixed>}|null
     */
    public function findRate(array $data, string $rateKey): ?array
    {
        $hotels = $data['hotels']['hotels'] ?? [];

        foreach ($hotels as $hotel) {
            if (! is_array($hotel)) {
                continue;
            }

            foreach ($hotel['rooms'] ?? [] as $room) {
                if (! is_array($room)) {
                    continue;
                }

                foreach ($room['rates'] ?? [] as $rate) {
                    if (is_array($rate) && ($rate['rateKey'] ?? null) === $rateKey) {
                        return [
                            'hotel' => $hotel,
                            'room' => $room,
                            'rate' => $rate,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{hotel: array<string, mixed>, room: array<string, mixed>, rate: array<string, mixed>}|null
     */
    public function firstCheckRate(array $data): ?array
    {
        $hotel = $data['hotel'] ?? null;

        if (! is_array($hotel)) {
            return null;
        }

        $room = $hotel['rooms'][0] ?? null;
        $rate = is_array($room) ? ($room['rates'][0] ?? null) : null;

        if (! is_array($room) || ! is_array($rate) || ! is_string($rate['rateKey'] ?? null)) {
            return null;
        }

        return compact('hotel', 'room', 'rate');
    }

    /**
     * @param  array<string, mixed>  $rate
     * @return array<string, mixed>
     */
    public function rate(array $rate): array
    {
        $taxes = [];
        $taxNode = $rate['taxes']['taxes'] ?? $rate['taxes'] ?? [];

        if (is_array($taxNode) && array_is_list($taxNode)) {
            foreach ($taxNode as $tax) {
                if (! is_array($tax)) {
                    continue;
                }

                $taxes[] = [
                    'sub_type' => $tax['subType'] ?? $tax['type'] ?? null,
                    'amount' => DecimalString::from($tax['amount'] ?? null),
                    'currency' => $tax['currency'] ?? null,
                    'included' => (bool) ($tax['included'] ?? false),
                    'client_amount' => DecimalString::from($tax['clientAmount'] ?? null),
                    'client_currency' => $tax['clientCurrency'] ?? null,
                ];
            }
        }

        $policies = [];

        foreach ($rate['cancellationPolicies'] ?? [] as $policy) {
            if (! is_array($policy)) {
                continue;
            }

            $policies[] = [
                'amount' => DecimalString::from($policy['amount'] ?? null),
                'from' => isset($policy['from']) ? (string) $policy['from'] : null,
            ];
        }

        return [
            'rate_key' => $rate['rateKey'],
            'rate_class' => $rate['rateClass'] ?? null,
            'rate_type' => $rate['rateType'] ?? null,
            'net' => DecimalString::from($rate['net'] ?? null),
            'allotment' => isset($rate['allotment']) ? (int) $rate['allotment'] : null,
            'payment_type' => $rate['paymentType'] ?? null,
            'packaging' => array_key_exists('packaging', $rate) ? (bool) $rate['packaging'] : null,
            'board_code' => $rate['boardCode'] ?? null,
            'board_name' => $rate['boardName'] ?? null,
            'rate_comments' => $this->comments($rate['rateComments'] ?? null),
            'promotions' => is_array($rate['promotions'] ?? null) ? $rate['promotions'] : [],
            'taxes' => $taxes,
            'cancellation_policies' => $policies,
            'adults' => $rate['adults'] ?? null,
            'children' => $rate['children'] ?? null,
            'rooms' => $rate['rooms'] ?? null,
        ];
    }

    private function comments(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value) && $value !== []) {
            return JsonDecimals::encode($value);
        }

        return null;
    }
}
