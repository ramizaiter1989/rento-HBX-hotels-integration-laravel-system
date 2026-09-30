<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasContentFacilityColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentRoomFacility extends Model
{
    use HasContentFacilityColumns;

    protected $fillable = [
        'content_hotel_room_id',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(ContentHotelRoom::class, 'content_hotel_room_id');
    }
}
