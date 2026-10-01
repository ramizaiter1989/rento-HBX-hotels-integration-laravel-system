<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentSyncRun extends Model
{
    public const FULL = 'full';

    public const DIFFERENTIAL = 'differential';

    public const REFERENCE = 'reference';

    public const RUNNING = 'running';

    public const FAILED = 'failed';

    public const COMPLETED = 'completed';

    public const STOPPED = 'stopped';

    protected $fillable = [
        'sync_type',
        'language',
        'last_update_time',
        'batch_size',
        'requested_limit',
        'supplier_total',
        'next_from',
        'fetched',
        'imported',
        'updated',
        'unchanged',
        'failed',
        'details_retained',
        'conflicts',
        'http_requests',
        'status',
        'stop_reason',
        'stop_requested',
        'started_at',
        'last_progress_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_update_time' => 'date',
            'batch_size' => 'integer',
            'requested_limit' => 'integer',
            'supplier_total' => 'integer',
            'next_from' => 'integer',
            'fetched' => 'integer',
            'imported' => 'integer',
            'updated' => 'integer',
            'unchanged' => 'integer',
            'failed' => 'integer',
            'details_retained' => 'integer',
            'conflicts' => 'integer',
            'http_requests' => 'integer',
            'stop_requested' => 'boolean',
            'started_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function failures(): HasMany
    {
        return $this->hasMany(ContentSyncFailure::class);
    }

    public function isResumable(): bool
    {
        return in_array($this->status, [self::RUNNING, self::STOPPED, self::FAILED], true)
            && ! $this->targetReached();
    }

    public function targetReached(): bool
    {
        return $this->requested_limit !== null && (int) $this->fetched >= (int) $this->requested_limit;
    }

    public function remaining(): ?int
    {
        if ($this->requested_limit === null) {
            return null;
        }

        return max(0, (int) $this->requested_limit - (int) $this->fetched);
    }
}
