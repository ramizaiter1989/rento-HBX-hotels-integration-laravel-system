<?php

declare(strict_types=1);

namespace App\DTOs\HBX;

use App\Models\HotelSearch;

final class AvailabilitySearchResult
{
    public const LIVE_HBX = 'LIVE_HBX';

    public const CACHE_HIT = 'CACHE_HIT';

    public function __construct(
        public readonly HotelSearch $search,
        public readonly string $source,
    ) {}

    public function message(): string
    {
        return $this->source === self::CACHE_HIT
            ? 'Reused a fresh availability snapshot.'
            : 'Fresh HBX availability received.';
    }
}
