<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingGuest extends Model
{
    protected $fillable = [
        'booking_room_id',
        'room_id',
        'type',
        'name',
        'surname',
        'age',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(BookingRoom::class, 'booking_room_id');
    }
}
