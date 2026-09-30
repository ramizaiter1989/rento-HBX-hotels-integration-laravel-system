<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\Models\HbxApiLog;
use App\Support\JsonDecimals;
use App\Support\PayloadSanitizer;
use Illuminate\Support\Facades\Log;

final class HbxApiLogger
{
    public function __construct(private readonly PayloadSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>|null  $requestPayload
     */
    public function write(
        string $operation,
        string $method,
        string $endpoint,
        ?int $httpStatus,
        bool $successful,
        ?array $requestPayload,
        ?string $responseBody,
        ?string $processTime,
        int $durationMs,
        ?string $errorCode,
        ?string $errorMessage,
        ?int $hotelBookingId = null,
        ?int $hotelSearchId = null,
        ?string $hbxReference = null,
        ?string $clientReference = null,
    ): HbxApiLog {
        $safeRequest = $requestPayload === null
            ? null
            : JsonDecimals::encode($this->sanitizer->sanitize($requestPayload));

        $safeResponse = $responseBody === null
            ? null
            : $this->sanitizer->redactString($responseBody);

        $safeMessage = $errorMessage === null
            ? null
            : $this->sanitizer->redactString($errorMessage);

        $log = HbxApiLog::query()->create([
            'hotel_booking_id' => $hotelBookingId,
            'hotel_search_id' => $hotelSearchId,
            'operation' => $operation,
            'request_method' => $method,
            'endpoint' => $endpoint,
            'http_status' => $httpStatus,
            'successful' => $successful,
            'request_payload' => $safeRequest,
            'response_payload' => $safeResponse,
            'supplier_process_time' => $processTime,
            'local_duration_ms' => $durationMs,
            'error_code' => $errorCode,
            'error_message' => $safeMessage,
            'hbx_reference' => $hbxReference,
            'client_reference' => $clientReference,
        ]);

        Log::info('HBX operation', [
            'operation' => $operation,
            'method' => $method,
            'endpoint' => $endpoint,
            'http_status' => $httpStatus,
            'successful' => $successful,
            'duration_ms' => $durationMs,
            'process_time' => $processTime,
            'error_code' => $errorCode,
            'hbx_reference' => $hbxReference,
            'client_reference' => $clientReference,
        ]);

        return $log;
    }
}
