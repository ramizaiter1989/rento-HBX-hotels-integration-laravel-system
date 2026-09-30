<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingTax extends Model
{
    protected $fillable = [
        'booking_room_id',
        'sub_type',
        'amount',
        'currency',
        'included',
        'client_amount',
        'client_currency',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'client_amount' => 'decimal:2',
            'included' => 'boolean',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(BookingRoom::class, 'booking_room_id');
    }
}
