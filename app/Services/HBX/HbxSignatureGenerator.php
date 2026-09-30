<?php

declare(strict_types=1);

namespace App\Services\HBX;

final class HbxSignatureGenerator
{
    public function generate(string $apiKey, string $secret, int $timestamp): string
    {
        return hash('sha256', $apiKey.$secret.$timestamp);
    }

    public function currentTimestamp(): int
    {
        return now()->getTimestamp();
    }
}
