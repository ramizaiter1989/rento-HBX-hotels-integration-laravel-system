<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HbxApiLog extends Model
{
    protected $fillable = [
        'hotel_booking_id',
        'hotel_search_id',
        'operation',
        'request_method',
        'endpoint',
        'http_status',
        'successful',
        'request_payload',
        'response_payload',
        'supplier_process_time',
        'local_duration_ms',
        'error_code',
        'error_message',
        'hbx_reference',
        'client_reference',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(HotelBooking::class, 'hotel_booking_id');
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(HotelSearch::class, 'hotel_search_id');
    }
}
