<?php

declare(strict_types=1);

namespace App\DTOs\HBX;

final class ContentSyncResult
{
    public int $fetched = 0;

    public int $imported = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $failed = 0;

    public int $detailsRetained = 0;

    public int $conflicts = 0;

    public int $httpRequests = 0;

    public ?string $runStatus = null;

    public bool $stopped = false;

    public ?string $stopReason = null;

    public float $durationSeconds = 0.0;
}
