<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentHotelInterestPointTranslation extends Model
{
    protected $fillable = [
        'content_hotel_interest_point_id',
        'language',
        'poi_name',
    ];

    public function interestPoint(): BelongsTo
    {
        return $this->belongsTo(ContentHotelInterestPoint::class, 'content_hotel_interest_point_id');
    }
}
