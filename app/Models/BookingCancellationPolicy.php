<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingCancellationPolicy extends Model
{
    protected $fillable = [
        'booking_room_id',
        'amount',
        'currency',
        'raw_from_value',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(BookingRoom::class, 'booking_room_id');
    }
}
