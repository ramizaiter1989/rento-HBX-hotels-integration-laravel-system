<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasContentFacilityColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentHotelFacility extends Model
{
    use HasContentFacilityColumns;

    protected $fillable = [
        'content_hotel_id',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(ContentHotel::class, 'content_hotel_id');
    }
}
