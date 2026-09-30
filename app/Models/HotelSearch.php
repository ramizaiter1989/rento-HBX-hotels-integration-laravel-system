<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotelSearch extends Model
{
    protected $fillable = [
        'destination_code',
        'hotel_codes',
        'check_in',
        'check_out',
        'rooms_count',
        'adults_count',
        'children_count',
        'child_ages',
        'request_payload',
        'response_payload',
        'hotels_returned',
        'search_fingerprint',
        'expires_at',
        'last_accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'hotel_codes' => 'array',
            'child_ages' => 'array',
            'expires_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(HotelBooking::class);
    }

    public function isFresh(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return ! $this->isFresh();
    }

    public function rateSelections(): HasMany
    {
        return $this->hasMany(RateSelection::class);
    }
}
