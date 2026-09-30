<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingRoom extends Model
{
    protected $fillable = [
        'hotel_booking_id',
        'supplier_room_id',
        'room_code',
        'room_name',
        'status',
        'board_code',
        'board_name',
        'rate_class',
        'rate_type',
        'rate_key',
        'net',
        'currency',
        'allotment',
        'packaging',
        'payment_type',
        'rate_comments',
        'promotions',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'net' => 'decimal:2',
            'packaging' => 'boolean',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(HotelBooking::class, 'hotel_booking_id');
    }

    public function guests(): HasMany
    {
        return $this->hasMany(BookingGuest::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(BookingTax::class);
    }

    public function cancellationPolicies(): HasMany
    {
        return $this->hasMany(BookingCancellationPolicy::class);
    }
}
