<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentHotelRoomTranslation extends Model
{
    protected $fillable = [
        'content_hotel_room_id',
        'language',
        'description',
        'commercial_description',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(ContentHotelRoom::class, 'content_hotel_room_id');
    }
}
