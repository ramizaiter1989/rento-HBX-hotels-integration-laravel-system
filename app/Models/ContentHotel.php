<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentHotel extends Model
{
    protected $fillable = [
        'hbx_hotel_code',
        'country_code',
        'country_iso_code',
        'state_code',
        'destination_code',
        'destination_country_code',
        'zone_code',
        'latitude',
        'longitude',
        'category_code',
        'category_group_code',
        'chain_code',
        'accommodation_type_code',
        'postal_code',
        'address_number',
        'email',
        'license',
        'giata_code',
        'web',
        'supplier_last_update',
        's2c',
        'ranking',
        'source_version',
    ];

    protected function casts(): array
    {
        return [
            'hbx_hotel_code' => 'integer',
            'zone_code' => 'integer',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'giata_code' => 'integer',
            'ranking' => 'integer',
            'supplier_last_update' => 'date',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ContentHotelTranslation::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ContentHotelSnapshot::class);
    }

    public function phones(): HasMany
    {
        return $this->hasMany(ContentHotelPhone::class);
    }

    public function boards(): HasMany
    {
        return $this->hasMany(ContentHotelBoard::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(ContentHotelSegment::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(ContentHotelRoom::class);
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(ContentHotelFacility::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ContentHotelImage::class)
            ->orderBy('visual_order')
            ->orderBy('source_position');
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(ContentHotelTerminal::class);
    }

    public function interestPoints(): HasMany
    {
        return $this->hasMany(ContentHotelInterestPoint::class);
    }
}
