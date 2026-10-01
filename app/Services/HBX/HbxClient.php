<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\DTOs\HBX\HbxResult;
use App\Exceptions\HBX\HbxAmbiguousResultException;
use App\Exceptions\HBX\HbxApiException;
use App\Exceptions\HBX\HbxAuthenticationException;
use App\Exceptions\HBX\HbxProductException;
use App\Exceptions\HBX\HbxValidationException;
use App\Support\JsonDecimals;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

final class HbxClient
{
    public function __construct(
        private readonly HbxSignatureGenerator $signatures,
        private readonly HbxApiLogger $logger,
    ) {}

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>  $context
     */
    public function get(string $path, array $query, string $operation, array $context = []): HbxResult
    {
        return $this->send('GET', $path, $query, null, $operation, $context);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $context
     */
    public function post(string $path, array $body, string $operation, array $context = []): HbxResult
    {
        return $this->send('POST', $path, [], $body, $operation, $context);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $context
     */
    public function put(string $path, array $body, string $operation, array $context = []): HbxResult
    {
        return $this->send('PUT', $path, [], $body, $operation, $context);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>  $context
     */
    public function delete(string $path, array $query, string $operation, array $context = []): HbxResult
    {
        return $this->send('DELETE', $path, $query, null, $operation, $context);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $context
     */
    private function send(string $method, string $path, array $query, ?array $body, string $operation, array $context): HbxResult
    {
        $this->assertConfigured();

        $endpoint = $this->endpoint($path, $query);
        $url = config('hbx.base_url').$endpoint;
        $attempts = $this->maxAttempts($method, $operation);
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $started = hrtime(true);

            try {
                $response = $this->dispatch($method, $url, $body);
            } catch (ConnectionException $exception) {
                $duration = $this->durationMs($started);
                $ambiguous = $this->isSideEffecting($operation);

                $this->logger->write(
                    operation: $operation,
                    method: $method,
                    endpoint: $endpoint,
                    httpStatus: null,
                    successful: false,
                    requestPayload: $body ?? $query,
                    responseBody: null,
                    processTime: null,
                    durationMs: $duration,
                    errorCode: $ambiguous ? 'AMBIGUOUS_RESULT' : 'CONNECTION_FAILED',
                    errorMessage: 'The HBX connection failed before a response was received.',
                    hotelBookingId: $context['hotel_booking_id'] ?? null,
                    hotelSearchId: $context['hotel_search_id'] ?? null,
                    hbxReference: $context['hbx_reference'] ?? null,
                    clientReference: $context['client_reference'] ?? null,
                );

                if ($attempt < $attempts) {
                    $this->pauseBeforeContentRetry($method, $operation, $attempt);

                    continue;
                }

                if ($ambiguous) {
                    throw new HbxAmbiguousResultException(
                        'The HBX request may have reached the supplier, but no response was received. Do not retry automatically. Reconcile with the client reference, Booking List, and Booking Detail.',
                        'AMBIGUOUS_RESULT',
                        null,
                        [],
                        $operation,
                    );
                }

                throw new HbxApiException(
                    'HBX could not be reached. The connection timed out or failed.',
                    'CONNECTION_FAILED',
                    null,
                    [],
                    $operation,
                );
            }

            $duration = $this->durationMs($started);
            $raw = $response->body();

            try {
                $data = JsonDecimals::decode($raw);
            } catch (JsonException) {
                $this->logger->write(
                    operation: $operation,
                    method: $method,
                    endpoint: $endpoint,
                    httpStatus: $response->status(),
                    successful: false,
                    requestPayload: $body ?? $query,
                    responseBody: $this->responseBodyForLog($operation, $raw, null, false),
                    processTime: null,
                    durationMs: $duration,
                    errorCode: 'INVALID_JSON',
                    errorMessage: 'HBX returned a response that was not valid JSON.',
                    hotelBookingId: $context['hotel_booking_id'] ?? null,
                    hotelSearchId: $context['hotel_search_id'] ?? null,
                    hbxReference: $context['hbx_reference'] ?? null,
                    clientReference: $context['client_reference'] ?? null,
                );

                throw new HbxApiException(
                    'HBX returned a response that was not valid JSON.',
                    'INVALID_JSON',
                    $response->status(),
                    [],
                    $operation,
                );
            }

            $processTime = isset($data['auditData']['processTime']) ? (string) $data['auditData']['processTime'] : null;
            $supplierTimestamp = isset($data['auditData']['timestamp']) ? (string) $data['auditData']['timestamp'] : null;
            $error = is_array($data['error'] ?? null) ? $data['error'] : null;
            $failed = $response->status() >= 400 || $error !== null;
            $errorCode = $error['code'] ?? ($failed ? 'HTTP_'.$response->status() : null);
            $errorMessage = $error['message'] ?? ($failed ? 'HBX request failed.' : null);

            $this->logger->write(
                operation: $operation,
                method: $method,
                endpoint: $endpoint,
                httpStatus: $response->status(),
                successful: ! $failed,
                requestPayload: $body ?? $query,
                responseBody: $this->responseBodyForLog($operation, $raw, $data, ! $failed),
                processTime: $processTime,
                durationMs: $duration,
                errorCode: is_string($errorCode) ? $errorCode : null,
                errorMessage: is_string($errorMessage) ? $errorMessage : null,
                hotelBookingId: $context['hotel_booking_id'] ?? null,
                hotelSearchId: $context['hotel_search_id'] ?? null,
                hbxReference: $context['hbx_reference'] ?? null,
                clientReference: $context['client_reference'] ?? null,
            );

            if ($failed) {
                if ($attempt < $attempts && $this->shouldRetryContentHttp($method, $operation, $response->status())) {
                    $this->pauseBeforeContentRetry($method, $operation, $attempt);

                    continue;
                }

                throw $this->exceptionFor($errorCode, is_string($errorMessage) ? $errorMessage : 'HBX request failed.', $response, $data, $operation);
            }

            return new HbxResult(
                operation: $operation,
                method: $method,
                endpoint: $endpoint,
                httpStatus: $response->status(),
                data: $data,
                rawBody: $raw,
                processTime: $processTime,
                durationMs: $duration,
                supplierTimestamp: $supplierTimestamp,
            );
        }

        throw $lastException ?? new HbxApiException('HBX request failed.', 'HBX_ERROR', null, [], $operation);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function dispatch(string $method, string $url, ?array $body): Response
    {
        $timestamp = $this->signatures->currentTimestamp();
        $apiKey = (string) config('hbx.api_key');
        $secret = (string) config('hbx.secret');

        $headers = [
            'Api-key' => $apiKey,
            'X-Signature' => $this->signatures->generate($apiKey, $secret, $timestamp),
            'Accept' => 'application/json',
        ];

        $pending = Http::withHeaders($headers)
            ->withOptions($this->httpOptions())
            ->timeout($this->timeoutFor($method))
            ->connectTimeout((int) config('hbx.connect_timeout'));

        if ($body !== null) {
            $pending = $pending->withBody(
                JsonDecimals::encode($body),
                'application/json'
            );
        }

        return $pending->send($method, $url);
    }

    /**
     * @return array<string, mixed>
     */
    private function httpOptions(): array
    {
        if (! config('hbx.mtls.enabled')) {
            return [];
        }

        $cert = (string) config('hbx.mtls.cert_path');
        $key = (string) config('hbx.mtls.key_path');

        if ($cert === '' || $key === '' || ! is_file($cert) || ! is_file($key)) {
            throw new HbxAuthenticationException(
                'mTLS is enabled, but the client certificate or private key path is not available.',
                'MTLS_NOT_CONFIGURED'
            );
        }

        return [
            'cert' => $cert,
            'ssl_key' => $key,
        ];
    }

    private function assertConfigured(): void
    {
        if (! config('hbx.enabled')) {
            throw new HbxAuthenticationException(
                'HBX is disabled. Set HBX_ENABLED=true in the local environment.',
                'AUTHENTICATION_FAILED'
            );
        }

        if (! is_string(config('hbx.api_key')) || config('hbx.api_key') === '' || ! is_string(config('hbx.secret')) || config('hbx.secret') === '') {
            throw new HbxAuthenticationException(
                'HBX credentials are not configured. Set HBX_API_KEY and HBX_SECRET in the local .env file.',
                'AUTHENTICATION_FAILED'
            );
        }
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function endpoint(string $path, array $query): string
    {
        $path = '/'.ltrim($path, '/');
        $filtered = array_filter($query, fn (mixed $value): bool => $value !== null && $value !== '');

        if ($filtered === []) {
            return $path;
        }

        return $path.'?'.http_build_query($filtered);
    }

    private function maxAttempts(string $method, string $operation): int
    {
        if ($this->isContentRead($method, $operation)) {
            return max(1, (int) config('hbx.content.get_retry_attempts', 3));
        }

        return strtoupper($method) === 'GET' ? 2 : 1;
    }

    private function isContentRead(string $method, string $operation): bool
    {
        return strtoupper($method) === 'GET'
            && in_array($operation, ['content_hotels', 'content_hotel_detail', 'content_reference'], true);
    }

    private function shouldRetryContentHttp(string $method, string $operation, int $status): bool
    {
        return $this->isContentRead($method, $operation) && ($status === 429 || $status >= 500);
    }

    private function pauseBeforeContentRetry(string $method, string $operation, int $attempt): void
    {
        if (! $this->isContentRead($method, $operation)) {
            return;
        }

        $base = max(0, (int) config('hbx.content.get_retry_base_ms', 250));
        $cap = max(0, (int) config('hbx.content.get_retry_cap_ms', 2000));
        $delay = min($cap, $base * (2 ** max(0, $attempt - 1)));

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    private function isSideEffecting(string $operation): bool
    {
        return in_array($operation, ['booking', 'cancellation', 'modification'], true);
    }

    private function timeoutFor(string $method): int
    {
        if (in_array(strtoupper($method), ['POST', 'PUT', 'DELETE'], true)) {
            return (int) config('hbx.booking_timeout');
        }

        return (int) config('hbx.timeout');
    }

    private function durationMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function exceptionFor(mixed $code, string $message, Response $response, array $data, string $operation): HbxApiException
    {
        $supplierCode = is_string($code) ? $code : 'HBX_ERROR';
        $class = match (true) {
            $response->status() === 401 || $response->status() === 403 => HbxAuthenticationException::class,
            $supplierCode === 'PRODUCT_ERROR' => HbxProductException::class,
            in_array($supplierCode, ['INVALID_DATA', 'INVALID_REQUEST'], true) => HbxValidationException::class,
            default => HbxApiException::class,
        };

        return new $class($message, $supplierCode, $response->status(), $data, $operation);
    }

    /**
     * Availability logs keep a short summary. The raw supplier body stays on hotel_searches.
     * Hotels list logs keep pagination metadata. Each imported hotel keeps its own snapshot.
     * Other operations keep their response bodies for booking and CheckRate diagnostics.
     *
     * @param  array<string, mixed>|null  $data
     */
    private function responseBodyForLog(string $operation, ?string $raw, ?array $data, bool $bodyStoredInHotelSearch): ?string
    {
        if ($operation === 'availability') {
            $hotels = is_array($data['hotels'] ?? null) ? $data['hotels'] : [];
            $audit = is_array($data['auditData'] ?? null) ? $data['auditData'] : [];
            $total = $hotels['total'] ?? null;

            return JsonDecimals::encode([
                'bodyStoredInHotelSearch' => $bodyStoredInHotelSearch,
                'responseBytes' => $raw === null ? 0 : strlen($raw),
                'hotelsTotal' => is_numeric($total) ? (int) $total : null,
                'processTime' => isset($audit['processTime']) ? (string) $audit['processTime'] : null,
                'timestamp' => isset($audit['timestamp']) ? (string) $audit['timestamp'] : null,
            ]);
        }

        if ($operation === 'content_hotels' || $operation === 'content_reference') {
            return $this->contentHotelsLogSummary($raw, $data);
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function contentHotelsLogSummary(?string $raw, ?array $data): string
    {
        $data = is_array($data) ? $data : [];
        $hotels = is_array($data['hotels'] ?? null) ? $data['hotels'] : [];
        $audit = is_array($data['auditData'] ?? null) ? $data['auditData'] : [];
        $total = $data['total'] ?? ($data['totalHotels'] ?? null);

        return JsonDecimals::encode([
            'responseBytes' => $raw === null ? 0 : strlen($raw),
            'from' => isset($data['from']) && is_numeric($data['from']) ? (int) $data['from'] : null,
            'to' => isset($data['to']) && is_numeric($data['to']) ? (int) $data['to'] : null,
            'total' => is_numeric($total) ? (int) $total : null,
            'hotelsReturned' => count($hotels),
            'itemsReturned' => $this->listedItemCount($data),
            'processTime' => isset($audit['processTime']) ? (string) $audit['processTime'] : null,
            'timestamp' => isset($audit['timestamp']) ? (string) $audit['timestamp'] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function listedItemCount(array $data): int
    {
        $count = 0;

        foreach ($data as $value) {
            if (is_array($value) && array_is_list($value)) {
                $count = max($count, count($value));
            }
        }

        return $count;
    }
}
