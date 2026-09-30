<?php

declare(strict_types=1);

namespace App\Exceptions\HBX;

use RuntimeException;

class HbxApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $supplierBody
     */
    public function __construct(
        string $message,
        public readonly ?string $supplierCode = null,
        public readonly ?int $httpStatus = null,
        public readonly array $supplierBody = [],
        public readonly ?string $operation = null,
    ) {
        parent::__construct($message);
    }

    public function heading(): string
    {
        return match ($this->supplierCode) {
            'INVALID_DATA', 'INVALID_REQUEST' => 'HBX rejected the request',
            'PRODUCT_ERROR' => 'Supplier product rule',
            'UNAUTHORIZED', 'AUTHENTICATION_FAILED', 'MTLS_NOT_CONFIGURED' => 'HBX authentication failed',
            'CONNECTION_FAILED' => 'HBX connection failed',
            'AMBIGUOUS_RESULT' => 'Supplier result is ambiguous',
            'MODIFICATION_UNVERIFIED' => 'Actual modification is pending verification',
            'CHECKRATE_REQUIRED' => 'CheckRate is required',
            'AVAILABILITY_SNAPSHOT_EXPIRED' => 'Availability snapshot expired',
            'RATE_SELECTION_EXPIRED' => 'Rate selection expired',
            default => 'HBX request failed',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        return [
            'heading' => $this->heading(),
            'code' => $this->supplierCode,
            'message' => $this->getMessage(),
            'http_status' => $this->httpStatus,
            'operation' => $this->operation,
            'detail' => $this->supplierBody === [] ? null : $this->supplierBody,
        ];
    }
}
