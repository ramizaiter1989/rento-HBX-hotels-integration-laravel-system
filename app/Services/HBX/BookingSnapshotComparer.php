<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\Support\DecimalString;
use App\Support\JsonDecimals;

final class BookingSnapshotComparer
{
    /**
     * @return array{stored: array<string, mixed>, live: array<string, mixed>, mismatches: list<string>}
     */
    public function compare(?string $storedRaw, ?string $liveRaw): array
    {
        $stored = $this->summary($storedRaw);
        $live = $this->summary($liveRaw);
        $mismatches = [];

        foreach (['status', 'holder_name', 'holder_surname', 'total_net', 'pending_amount', 'currency', 'hotel_name', 'cancellation_reference'] as $field) {
            if (($stored[$field] ?? null) !== ($live[$field] ?? null)) {
                $mismatches[] = $field;
            }
        }

        return [
            'stored' => $stored,
            'live' => $live,
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $data = JsonDecimals::decode($raw);
        $node = is_array($data['booking'] ?? null) ? $data['booking'] : [];
        $hotel = is_array($node['hotel'] ?? null) ? $node['hotel'] : [];
        $holder = is_array($node['holder'] ?? null) ? $node['holder'] : [];

        return [
            'status' => $node['status'] ?? null,
            'reference' => $node['reference'] ?? null,
            'client_reference' => $node['clientReference'] ?? null,
            'holder_name' => $holder['name'] ?? null,
            'holder_surname' => $holder['surname'] ?? null,
            'hotel_name' => $hotel['name'] ?? null,
            'hotel_code' => isset($hotel['code']) ? (string) $hotel['code'] : null,
            'check_in' => $hotel['checkIn'] ?? null,
            'check_out' => $hotel['checkOut'] ?? null,
            'total_net' => DecimalString::from($node['totalNet'] ?? $hotel['totalNet'] ?? null),
            'pending_amount' => DecimalString::from($node['pendingAmount'] ?? null),
            'currency' => $node['currency'] ?? $hotel['currency'] ?? null,
            'cancellation_reference' => $node['cancellationReference'] ?? null,
            'creation_date' => $node['creationDate'] ?? null,
        ];
    }
}
