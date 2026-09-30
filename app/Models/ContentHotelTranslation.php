<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentHotelTranslation extends Model
{
    protected $fillable = [
        'content_hotel_id',
        'language',
        'name',
        'description',
        'address_line',
        'address_street',
        'city',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(ContentHotel::class, 'content_hotel_id');
    }
}
