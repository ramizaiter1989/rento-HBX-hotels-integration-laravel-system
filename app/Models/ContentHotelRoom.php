<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentHotelRoom extends Model
{
    protected $fillable = [
        'content_hotel_id',
        'room_code',
        'type_code',
        'characteristic_code',
        'is_parent_room',
        'min_pax',
        'max_pax',
        'min_adults',
        'max_adults',
        'max_children',
        'pms_room_code',
    ];

    protected function casts(): array
    {
        return [
            'is_parent_room' => 'boolean',
            'min_pax' => 'integer',
            'max_pax' => 'integer',
            'min_adults' => 'integer',
            'max_adults' => 'integer',
            'max_children' => 'integer',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(ContentHotel::class, 'content_hotel_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ContentHotelRoomTranslation::class);
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(ContentRoomFacility::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(ContentRoomStay::class);
    }
}
