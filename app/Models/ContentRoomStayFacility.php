<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasContentFacilityColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentRoomStayFacility extends Model
{
    use HasContentFacilityColumns;

    protected $fillable = [
        'content_room_stay_id',
    ];

    public function stay(): BelongsTo
    {
        return $this->belongsTo(ContentRoomStay::class, 'content_room_stay_id');
    }
}
