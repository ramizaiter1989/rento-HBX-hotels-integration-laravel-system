<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentSyncFailure extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'content_sync_run_id',
        'hbx_hotel_code',
        'failure_type',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'hbx_hotel_code' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContentSyncRun::class, 'content_sync_run_id');
    }
}
