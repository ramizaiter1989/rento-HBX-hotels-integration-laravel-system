<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentHotelInterestPoint extends Model
{
    protected $fillable = [
        'content_hotel_id',
        'facility_code',
        'facility_group_code',
        'sort_order',
        'distance',
    ];

    protected function casts(): array
    {
        return [
            'facility_code' => 'integer',
            'facility_group_code' => 'integer',
            'sort_order' => 'integer',
            'distance' => 'integer',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(ContentHotel::class, 'content_hotel_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ContentHotelInterestPointTranslation::class);
    }
}
