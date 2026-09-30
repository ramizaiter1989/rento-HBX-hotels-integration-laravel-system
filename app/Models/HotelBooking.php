<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\JsonDecimals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotelBooking extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    protected $fillable = [
        'hotel_search_id',
        'rate_selection_id',
        'hbx_reference',
        'client_reference',
        'submission_token',
        'status',
        'holder_name',
        'holder_surname',
        'hotel_code',
        'hotel_name',
        'category_name',
        'destination_name',
        'zone_name',
        'check_in',
        'check_out',
        'currency',
        'total_net',
        'pending_amount',
        'payment_type',
        'payment_data_required',
        'supplier_name',
        'supplier_vat',
        'creation_date',
        'modification_allowed',
        'cancellation_allowed',
        'cancellation_reference',
        'cancellation_amount',
        'remark',
        'raw_booking_response',
        'live_hbx_response',
        'last_hbx_sync_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'total_net' => 'decimal:2',
            'pending_amount' => 'decimal:2',
            'cancellation_amount' => 'decimal:2',
            'payment_data_required' => 'boolean',
            'modification_allowed' => 'boolean',
            'cancellation_allowed' => 'boolean',
            'last_hbx_sync_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(HotelSearch::class, 'hotel_search_id');
    }

    public function rateSelection(): BelongsTo
    {
        return $this->belongsTo(RateSelection::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class);
    }

    public function simulations(): HasMany
    {
        return $this->hasMany(BookingSimulation::class);
    }

    public function apiLogs(): HasMany
    {
        return $this->hasMany(HbxApiLog::class);
    }

    public function rawData(): array
    {
        return $this->decode($this->raw_booking_response);
    }

    public function liveData(): array
    {
        return $this->decode($this->live_hbx_response);
    }

    public function isCancellable(): bool
    {
        return $this->status === 'CONFIRMED';
    }

    private function decode(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        return JsonDecimals::decode($value);
    }
}
