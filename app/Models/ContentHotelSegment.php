<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentHotelSegment extends Model
{
    protected $fillable = [
        'content_hotel_id',
        'segment_code',
    ];

    protected function casts(): array
    {
        return [
            'segment_code' => 'integer',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(ContentHotel::class, 'content_hotel_id');
    }
}
