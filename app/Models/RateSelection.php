<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\JsonDecimals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RateSelection extends Model
{
    protected $fillable = [
        'hotel_search_id',
        'hotel_code',
        'hotel_name',
        'category_name',
        'destination_code',
        'destination_name',
        'zone_name',
        'latitude',
        'longitude',
        'room_code',
        'room_name',
        'original_rate_key',
        'rate_key',
        'original_rate_type',
        'rate_type',
        'rate_class',
        'net',
        'availability_net',
        'currency',
        'allotment',
        'board_code',
        'board_name',
        'payment_type',
        'packaging',
        'payment_data_required',
        'rate_comments',
        'taxes',
        'cancellation_policies',
        'promotions',
        'checkrate_response',
        'checkrate_completed_at',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'net' => 'decimal:2',
            'availability_net' => 'decimal:2',
            'packaging' => 'boolean',
            'payment_data_required' => 'boolean',
            'checkrate_completed_at' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(HotelSearch::class, 'hotel_search_id');
    }

    public function bookingAllowed(): bool
    {
        if ($this->original_rate_type === 'RECHECK' && $this->checkrate_completed_at === null) {
            return false;
        }

        return $this->rate_type === 'BOOKABLE';
    }

    public function isWithinValidity(): bool
    {
        return $this->valid_until !== null && $this->valid_until->greaterThan(now());
    }

    public function taxesData(): array
    {
        return $this->decode($this->taxes);
    }

    public function policiesData(): array
    {
        return $this->decode($this->cancellation_policies);
    }

    public function promotionsData(): array
    {
        return $this->decode($this->promotions);
    }

    private function decode(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = JsonDecimals::decode($value);

        return array_is_list($decoded) ? $decoded : $decoded;
    }
}
