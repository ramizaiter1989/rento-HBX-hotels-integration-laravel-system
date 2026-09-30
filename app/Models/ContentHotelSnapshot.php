<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentHotelSnapshot extends Model
{
    protected $fillable = [
        'content_hotel_id',
        'language',
        'raw_payload',
        'content_hash',
        'payload_bytes',
        'content_origin',
        'content_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_bytes' => 'integer',
            'content_synced_at' => 'datetime',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(ContentHotel::class, 'content_hotel_id');
    }
}
