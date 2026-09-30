<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentRoomStay extends Model
{
    protected $fillable = [
        'content_hotel_room_id',
        'stay_type',
        'stay_order',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(ContentHotelRoom::class, 'content_hotel_room_id');
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(ContentRoomStayFacility::class);
    }
}
