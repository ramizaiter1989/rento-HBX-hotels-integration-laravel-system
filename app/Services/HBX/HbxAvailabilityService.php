<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\DTOs\HBX\AvailabilityQuery;
use App\DTOs\HBX\AvailabilitySearchResult;
use App\Models\HotelSearch;
use App\Support\JsonDecimals;
use App\Support\PositiveConfigInt;
use Illuminate\Support\Carbon;

final class HbxAvailabilityService
{
    public function __construct(private readonly HbxClient $client) {}

    public function search(AvailabilityQuery $query): AvailabilitySearchResult
    {
        $fingerprint = $query->fingerprint();
        $fresh = HotelSearch::query()
            ->where('search_fingerprint', $fingerprint)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', Carbon::now())
            ->orderByDesc('id')
            ->first();

        if ($fresh instanceof HotelSearch) {
            $fresh->forceFill(['last_accessed_at' => Carbon::now()])->save();

            return new AvailabilitySearchResult($fresh, AvailabilitySearchResult::CACHE_HIT);
        }

        $payload = $query->toPayload();
        $result = $this->client->post(
            (string) config('hbx.endpoints.availability'),
            $payload,
            'availability'
        );

        $total = (int) ($result->data['hotels']['total'] ?? 0);
        $now = Carbon::now();
        $ttl = PositiveConfigInt::from(config('hbx.availability.cache_ttl_seconds'), 60);

        $search = HotelSearch::query()->create([
            'destination_code' => $query->destinationCode,
            'hotel_codes' => $query->hotelCodes === [] ? null : $query->hotelCodes,
            'check_in' => $query->checkIn,
            'check_out' => $query->checkOut,
            'rooms_count' => $query->rooms,
            'adults_count' => $query->adults,
            'children_count' => $query->children,
            'child_ages' => $query->childAges === [] ? null : $query->childAges,
            'request_payload' => JsonDecimals::encode($payload),
            'response_payload' => $result->rawBody,
            'hotels_returned' => $total,
            'search_fingerprint' => $fingerprint,
            'expires_at' => $now->copy()->addSeconds($ttl),
            'last_accessed_at' => $now,
        ]);

        return new AvailabilitySearchResult($search, AvailabilitySearchResult::LIVE_HBX);
    }
}
