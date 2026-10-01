<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\DTOs\HBX\ContentImportResult;
use App\DTOs\HBX\ContentSyncResult;
use App\DTOs\HBX\HbxResult;
use App\Exceptions\HBX\HbxValidationException;
use App\Models\ContentSyncFailure;
use App\Models\ContentSyncRun;
use App\Support\JsonDecimals;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class HbxContentHotelSyncService
{
    /**
     * Hard stop for a supplier page that never advances. A full catalog at the
     * default batch is a few thousand pages, so this is only a loop guard.
     */
    private const MAX_PAGES = 20000;

    /**
     * One active hotel-sync worker per run. Refreshed on each page. A dead
     * process releases the claim when this window expires.
     */
    private const WORKER_LOCK_SECONDS = 600;

    public const WORKER_LOCK_PREFIX = 'hbx-content-sync-run-';

    private bool $stopRequested = false;

    public function __construct(
        private readonly HbxContentHotelsService $catalog,
        private readonly ContentHotelListNormalizer $normalizer,
        private readonly HbxContentHotelImporter $importer,
    ) {}

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    public function sync(
        string $language,
        int $batch,
        int $from,
        ?int $limit,
        ?string $lastUpdate,
        ContentSyncRun $run,
        ?callable $onPage = null,
        ?callable $onFailure = null,
    ): ContentSyncResult {
        $this->assertRange($batch, $from, $limit);
        $owner = (string) Str::uuid();
        $workerKey = self::WORKER_LOCK_PREFIX.$run->id;

        if (! Cache::add($workerKey, $owner, self::WORKER_LOCK_SECONDS)) {
            throw new HbxValidationException(
                'Another worker is already processing content sync run '.$run->id.'.',
                'SYNC_WORKER_ACTIVE',
                null,
                [],
                'content_hotels'
            );
        }

        try {
            return $this->syncWhileOwned($language, $batch, $from, $limit, $lastUpdate, $run, $workerKey, $owner, $onPage, $onFailure);
        } finally {
            if (Cache::get($workerKey) === $owner) {
                Cache::forget($workerKey);
            }
        }
    }

    /**
     * @param  callable(int, int, ?int): void|null  $onPage
     * @param  callable(string, string): void|null  $onFailure
     */
    private function syncWhileOwned(
        string $language,
        int $batch,
        int $from,
        ?int $limit,
        ?string $lastUpdate,
        ContentSyncRun $run,
        string $workerKey,
        string $owner,
        ?callable $onPage,
        ?callable $onFailure,
    ): ContentSyncResult {
        $counts = $this->countsFrom($run);
        $started = hrtime(true);
        $position = $from;
        $seen = [];
        $pages = 0;
        $budget = self::MAX_PAGES;
        $knownTotal = $run->supplier_total;
        $sessionFetched = 0;
        $finishStatus = ContentSyncRun::COMPLETED;
        $finishReason = null;

        $run->forceFill([
            'status' => ContentSyncRun::RUNNING,
            'stop_requested' => false,
            'stop_reason' => null,
            'completed_at' => null,
        ])->save();

        while (true) {
            $run->refresh();
            $this->keepWorker($workerKey, $owner);

            if ($run->stop_requested || $this->stopRequested) {
                $finishStatus = ContentSyncRun::STOPPED;
                $finishReason = 'Content sync stopped between pages.';
                break;
            }

            if ($limit !== null && $sessionFetched >= $limit) {
                $finishStatus = ContentSyncRun::COMPLETED;
                $finishReason = 'Content sync reached the requested limit.';
                break;
            }

            if (isset($seen[$position]) || $pages >= $budget) {
                $counts->stopped = true;
                $finishStatus = ContentSyncRun::STOPPED;
                $finishReason = 'Content sync stopped because the supplier page did not advance.';
                break;
            }

            $window = $batch;

            if ($limit !== null) {
                $window = min($window, $limit - $sessionFetched);
            }

            if ($window < 1) {
                $finishStatus = ContentSyncRun::COMPLETED;
                $finishReason = 'Content sync reached the requested limit.';
                break;
            }

            $requestedTo = $position + $window - 1;

            if ($knownTotal !== null && $requestedTo > $knownTotal) {
                $requestedTo = $knownTotal;
            }

            if ($requestedTo < $position) {
                break;
            }

            $seen[$position] = true;
            $pages++;

            try {
                $page = $this->catalog->catalogPage($position, $requestedTo, $language, $lastUpdate);
                $counts->httpRequests++;
            } catch (HbxValidationException $exception) {
                if ($exception->httpStatus !== null) {
                    $counts->httpRequests++;
                }

                $counts->stopped = true;
                $finishStatus = ContentSyncRun::FAILED;
                $finishReason = $exception->getMessage();
                break;
            } catch (Throwable $exception) {
                $counts->httpRequests++;
                $counts->stopped = true;
                $finishStatus = ContentSyncRun::FAILED;
                $finishReason = $exception->getMessage();
                break;
            }

            $data = $page->data;
            $hotels = $data['hotels'] ?? null;
            $total = $this->total($data);
            $responseTo = $this->integer($data['to'] ?? null);
            $shownFrom = $this->integer($data['from'] ?? null) ?? $position;
            $shownTo = $responseTo ?? $requestedTo;

            if ($onPage !== null) {
                $onPage($shownFrom, $shownTo, $total);
            }

            if ($total !== null) {
                $knownTotal = $total;
                $budget = min(self::MAX_PAGES, (int) ceil($total / max(1, $batch)) + 2);
            }

            if (! is_array($hotels) || $hotels === []) {
                unset($page, $data, $hotels);
                break;
            }

            $version = $data['version'] ?? null;
            $processTime = $page->processTime;
            $timestamp = $page->supplierTimestamp;
            $returned = count($hotels);

            foreach ($hotels as $hotel) {
                $this->importHotel($hotel, $version, $processTime, $timestamp, $language, $counts, $onFailure, $run);
            }

            $counts->fetched += $returned;
            $sessionFetched += $returned;
            unset($page, $data, $hotels, $hotel);

            $next = ($responseTo ?? $requestedTo) + 1;
            $catalogEnded = $next <= $position || ($total !== null && ($responseTo ?? $requestedTo) >= $total);

            if ($next > $position) {
                $position = $next;
            }

            $this->checkpoint($run, $counts, $knownTotal, $position);

            if ($catalogEnded) {
                break;
            }
        }

        $this->finish($run, $counts, $knownTotal, $position, $finishStatus, $finishReason);
        $counts->runStatus = $finishStatus;
        $counts->stopReason = $finishReason;
        $counts->durationSeconds = (hrtime(true) - $started) / 1_000_000_000;

        return $counts;
    }

    private function keepWorker(string $workerKey, string $owner): void
    {
        if (Cache::get($workerKey) !== $owner) {
            throw new HbxValidationException(
                'This content sync worker lost content sync run '.substr($workerKey, strlen(self::WORKER_LOCK_PREFIX)).'.',
                'SYNC_WORKER_LOST',
                null,
                [],
                'content_hotels'
            );
        }

        Cache::put($workerKey, $owner, self::WORKER_LOCK_SECONDS);
    }

    private function countsFrom(ContentSyncRun $run): ContentSyncResult
    {
        $counts = new ContentSyncResult;
        $counts->fetched = (int) $run->fetched;
        $counts->imported = (int) $run->imported;
        $counts->updated = (int) $run->updated;
        $counts->unchanged = (int) $run->unchanged;
        $counts->failed = (int) $run->failed;
        $counts->detailsRetained = (int) $run->details_retained;
        $counts->conflicts = (int) $run->conflicts;
        $counts->httpRequests = (int) $run->http_requests;

        return $counts;
    }

    private function checkpoint(ContentSyncRun $run, ContentSyncResult $counts, ?int $total, int $nextFrom): void
    {
        $run->forceFill([
            'supplier_total' => $total,
            'next_from' => $nextFrom,
            'fetched' => $counts->fetched,
            'imported' => $counts->imported,
            'updated' => $counts->updated,
            'unchanged' => $counts->unchanged,
            'failed' => $counts->failed,
            'details_retained' => $counts->detailsRetained,
            'conflicts' => $counts->conflicts,
            'http_requests' => $counts->httpRequests,
            'status' => ContentSyncRun::RUNNING,
            'last_progress_at' => now(),
        ])->save();
    }

    private function finish(ContentSyncRun $run, ContentSyncResult $counts, ?int $total, int $nextFrom, string $status, ?string $reason): void
    {
        $run->forceFill([
            'supplier_total' => $total ?? $run->supplier_total,
            'next_from' => $nextFrom,
            'fetched' => $counts->fetched,
            'imported' => $counts->imported,
            'updated' => $counts->updated,
            'unchanged' => $counts->unchanged,
            'failed' => $counts->failed,
            'details_retained' => $counts->detailsRetained,
            'conflicts' => $counts->conflicts,
            'http_requests' => $counts->httpRequests,
            'status' => $status,
            'stop_reason' => $reason,
            'stop_requested' => false,
            'last_progress_at' => now(),
            'completed_at' => now(),
        ])->save();
    }

    /**
     * @param  mixed  $version
     */
    private function importHotel(
        mixed $hotel,
        mixed $version,
        ?string $processTime,
        ?string $timestamp,
        string $language,
        ContentSyncResult $counts,
        ?callable $onFailure,
        ContentSyncRun $run,
    ): void {
        if (! is_array($hotel)) {
            $counts->failed++;
            $this->recordFailure($run, null, 'invalid-payload', 'Content hotel must be an object.');
            if ($onFailure !== null) {
                $onFailure('unknown', 'Content hotel must be an object.');
            }

            return;
        }

        $code = $this->hotelCodeLabel($hotel);

        try {
            $normalized = $this->normalizer->normalize($hotel);
            $document = ['hotel' => $normalized];

            if (is_string($version) && $version !== '') {
                $document = ['version' => $version, 'hotel' => $normalized];
            }

            $raw = JsonDecimals::encode($document);
            $synthetic = new HbxResult(
                operation: 'content_hotel_detail',
                method: 'GET',
                endpoint: 'content-sync',
                httpStatus: 200,
                data: JsonDecimals::decode($raw),
                rawBody: $raw,
                processTime: $processTime,
                durationMs: 0,
                supplierTimestamp: $timestamp,
            );
            $imported = $this->importer->import($synthetic, $language, $this->expectedCode($hotel), 'list');

            foreach ($imported->conflicts as $conflict) {
                if ($conflict === 'hotel-details-retained') {
                    $counts->detailsRetained++;

                    continue;
                }

                $counts->conflicts++;
            }

            match ($imported->status) {
                ContentImportResult::IMPORTED => $counts->imported++,
                ContentImportResult::UPDATED => $counts->updated++,
                ContentImportResult::UNCHANGED => $counts->unchanged++,
                default => $counts->failed++,
            };

            unset($normalized, $document, $raw, $synthetic, $imported);
        } catch (Throwable $exception) {
            $counts->failed++;
            $message = strtok($exception->getMessage(), "\n") ?: 'Import failed.';
            $this->recordFailure($run, $this->expectedCode($hotel), 'import', mb_substr($message, 0, 500));
            if ($onFailure !== null) {
                $onFailure($code, $exception->getMessage());
            }
        }
    }

    private function recordFailure(ContentSyncRun $run, ?int $hotelCode, string $type, string $message): void
    {
        ContentSyncFailure::query()->create([
            'content_sync_run_id' => $run->id,
            'hbx_hotel_code' => $hotelCode,
            'failure_type' => $type,
            'message' => mb_substr($message, 0, 500),
        ]);
    }

    private function assertRange(int $batch, int $from, ?int $limit): void
    {
        if ($batch < 1 || $batch > HbxContentHotelsService::MAX_SYNC_BATCH) {
            throw new HbxValidationException(
                'Content sync batch must be a whole number from 1 to '.HbxContentHotelsService::MAX_SYNC_BATCH.'.',
                'INVALID_DATA',
                null,
                [],
                'content_hotels'
            );
        }

        if ($from < 1) {
            throw new HbxValidationException(
                'Content sync from must start at 1 or later.',
                'INVALID_DATA',
                null,
                [],
                'content_hotels'
            );
        }

        if ($limit !== null && $limit < 1) {
            throw new HbxValidationException(
                'Content sync limit must be a whole number of hotels.',
                'INVALID_DATA',
                null,
                [],
                'content_hotels'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function total(array $data): ?int
    {
        $total = $data['total'] ?? ($data['totalHotels'] ?? null);

        return $this->integer($total);
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $hotel
     */
    private function hotelCodeLabel(array $hotel): string
    {
        $code = $this->expectedCode($hotel);

        return $code === null ? 'unknown' : (string) $code;
    }

    /**
     * @param  array<string, mixed>  $hotel
     */
    private function expectedCode(array $hotel): ?int
    {
        $code = $hotel['code'] ?? null;

        if (is_int($code) && $code > 0) {
            return $code;
        }

        if (is_string($code) && preg_match('/^\d+$/', $code) === 1) {
            return (int) $code;
        }

        return null;
    }
}
