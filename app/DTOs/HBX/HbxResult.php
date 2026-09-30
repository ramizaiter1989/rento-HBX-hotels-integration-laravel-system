<?php

declare(strict_types=1);

namespace App\DTOs\HBX;

final class HbxResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $method,
        public readonly string $endpoint,
        public readonly int $httpStatus,
        public readonly array $data,
        public readonly string $rawBody,
        public readonly ?string $processTime,
        public readonly int $durationMs,
        public readonly ?string $supplierTimestamp,
    ) {}

    public function bookingNode(): array
    {
        $booking = $this->data['booking'] ?? [];

        return is_array($booking) ? $booking : [];
    }
}
