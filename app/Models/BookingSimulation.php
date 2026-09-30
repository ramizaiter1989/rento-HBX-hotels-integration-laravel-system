<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingSimulation extends Model
{
    protected $fillable = [
        'hotel_booking_id',
        'type',
        'simulated_status',
        'cancellation_amount',
        'currency',
        'supplier_cancellation_reference',
        'holder_name',
        'holder_surname',
        'request_payload',
        'response_payload',
        'simulated_at',
    ];

    protected function casts(): array
    {
        return [
            'cancellation_amount' => 'decimal:2',
            'simulated_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(HotelBooking::class, 'hotel_booking_id');
    }
}
