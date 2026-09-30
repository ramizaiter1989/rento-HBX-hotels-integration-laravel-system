<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentHotelTerminal extends Model
{
    protected $fillable = [
        'content_hotel_id',
        'terminal_code',
        'terminal_type',
        'distance',
    ];

    protected function casts(): array
    {
        return [
            'distance' => 'integer',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(ContentHotel::class, 'content_hotel_id');
    }
}
