<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\DTOs\HBX\HbxResult;

final class HbxStatusService
{
    public function __construct(private readonly HbxClient $client) {}

    public function check(): HbxResult
    {
        return $this->client->get(
            (string) config('hbx.endpoints.status'),
            [],
            'status'
        );
    }
}
