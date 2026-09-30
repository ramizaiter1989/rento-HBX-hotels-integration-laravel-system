<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentHotelImage extends Model
{
    /**
     * source_position is the zero-based index of this row in the supplier images array.
     * It identifies one import of that array slot. It is not a Hotelbeds business key.
     * Gallery order is visual_order, then source_position.
     */
    protected $fillable = [
        'content_hotel_id',
        'content_hotel_room_id',
        'image_type_code',
        'path',
        'room_code',
        'room_type_code',
        'characteristic_code',
        'supplier_order',
        'visual_order',
        'source_position',
    ];

    protected function casts(): array
    {
        return [
            'supplier_order' => 'integer',
            'visual_order' => 'integer',
            'source_position' => 'integer',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(ContentHotel::class, 'content_hotel_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ContentHotelRoom::class, 'content_hotel_room_id');
    }
}
