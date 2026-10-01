<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\HBX\HbxValidationException;
use App\Models\ContentSyncRun;
use App\Services\HBX\HbxContentHotelsService;
use App\Services\HBX\HbxContentHotelSyncService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Throwable;

class HbxContentSyncHotelsCommand extends Command implements SignalableCommandInterface
{
    private ?HbxContentHotelSyncService $sync = null;

    protected $signature = 'hbx:content:sync-hotels
        {--language=ENG : Content language code. ENG is the structural language}
        {--batch= : Hotels per request. Defaults to the configured batch. Maximum 100}
        {--from=1 : First result position, starting at 1}
        {--limit= : Hotel target for a new run. Resume keeps the stored target}
        {--last-update= : Send lastUpdateTime as YYYY-MM-DD}
        {--resume : Continue the latest stopped, failed, or interrupted run}';

    protected $description = 'Import a paged Hotels Content API catalog into the local content tables';

    public function getSubscribedSignals(): array
    {
        if (! extension_loaded('pcntl')) {
            return [];
        }

        return [SIGINT, SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->sync?->requestStop();

        return false;
    }

    public function handle(HbxContentHotelSyncService $sync, HbxContentHotelsService $hotels): int
    {
        $this->sync = $sync;

        try {
            $resume = (bool) $this->option('resume');
            $language = strtoupper(trim((string) $this->option('language')));
            $language = $language === '' ? 'ENG' : $language;
            $lastUpdate = $this->option('last-update');
            $lastUpdate = is_string($lastUpdate) ? trim($lastUpdate) : null;
            $lastUpdate = $hotels->lastUpdateTime($lastUpdate === '' ? null : $lastUpdate);
            $requestedLimit = $this->optionalWholeNumber('limit', 'Content sync limit must be a whole number of hotels.');

            if ($resume && $requestedLimit !== null) {
                $this->warn('The --limit option is ignored on resume. The stored target is used.');
            }

            $run = $resume
                ? $this->runToResume($language, $lastUpdate)
                : $this->startRun($language, $this->batch(), $this->wholeNumber('from', 'Content sync from must start at 1 or later.'), $lastUpdate, $requestedLimit);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $language = $run->language;
        $batch = $run->batch_size;
        $from = $run->next_from;
        $lastUpdate = $run->last_update_time?->format('Y-m-d');
        $limit = $run->remaining();

        $this->line('Run: '.$run->id);
        $this->line('Type: '.$run->sync_type);
        $this->line('Resume: '.($resume ? 'yes' : 'no'));
        $this->line('Language: '.$language);
        $this->line('Batch: '.$batch);
        $this->line('From: '.$from);
        $this->line('Requested target: '.($run->requested_limit === null ? 'none' : (string) $run->requested_limit));
        $this->line('Fetched total: '.$run->fetched);
        $this->line('Remaining: '.($limit === null ? 'none' : (string) $limit));
        $this->line('lastUpdateTime: '.($lastUpdate ?? 'not sent'));

        try {
            $result = $sync->sync(
                $language,
                $batch,
                $from,
                $limit,
                $lastUpdate,
                $run,
                function (int $pageFrom, int $pageTo, ?int $total): void {
                    $this->line('Page: '.$pageFrom.'-'.$pageTo.' / '.($total === null ? 'unknown' : (string) $total));
                },
                function (string $code, string $message): void {
                    $this->line('Failed: '.$code.' '.$message);
                },
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result->stopReason !== null) {
            $this->error($result->stopReason);
        }

        $this->line('Fetched: '.$result->fetched);
        $this->line('Imported: '.$result->imported);
        $this->line('Updated: '.$result->updated);
        $this->line('Unchanged: '.$result->unchanged);
        $this->line('Failed: '.$result->failed);
        $this->line('Details retained: '.$result->detailsRetained);
        $this->line('Conflicts: '.$result->conflicts);
        $this->line('HTTP requests: '.$result->httpRequests);
        $this->line('Duration: '.number_format($result->durationSeconds, 2, '.', '').'s');
        $this->line('Status: '.($result->runStatus ?? $run->status));
        $this->line('Next from: '.$run->refresh()->next_from);

        return ($result->failed > 0 || $result->stopped) ? self::FAILURE : self::SUCCESS;
    }

    private function startRun(string $language, int $batch, int $from, ?string $lastUpdate, ?int $requestedLimit): ContentSyncRun
    {
        $run = ContentSyncRun::query()->create([
            'sync_type' => $lastUpdate === null ? ContentSyncRun::FULL : ContentSyncRun::DIFFERENTIAL,
            'language' => $language,
            'last_update_time' => $lastUpdate,
            'batch_size' => $batch,
            'requested_limit' => $requestedLimit,
            'next_from' => $from,
            'status' => ContentSyncRun::RUNNING,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);
        $run->refresh();

        return $run;
    }

    private function runToResume(string $language, ?string $lastUpdate): ContentSyncRun
    {
        $type = $lastUpdate === null ? ContentSyncRun::FULL : ContentSyncRun::DIFFERENTIAL;
        $run = ContentSyncRun::query()
            ->where('sync_type', $type)
            ->where('language', $language)
            ->when(
                $lastUpdate === null,
                fn ($query) => $query->whereNull('last_update_time'),
                fn ($query) => $query->whereDate('last_update_time', $lastUpdate),
            )
            ->orderByDesc('id')
            ->first();

        if ($run === null) {
            throw new HbxValidationException(
                'No '.$type.' content sync run exists to resume.',
                'INVALID_DATA',
                null,
                [],
                'content_hotels'
            );
        }

        if ($run->status === ContentSyncRun::COMPLETED || $run->targetReached()) {
            $reason = $run->targetReached()
                ? 'already reached its requested limit and cannot be resumed.'
                : 'is completed and cannot be resumed.';

            throw new HbxValidationException(
                'The latest '.$type.' content sync run '.$reason,
                'INVALID_DATA',
                null,
                [],
                'content_hotels'
            );
        }

        if (! $run->isResumable()) {
            throw new HbxValidationException(
                'The latest '.$type.' content sync run cannot be resumed.',
                'INVALID_DATA',
                null,
                [],
                'content_hotels'
            );
        }

        return $run;
    }

    private function batch(): int
    {
        $option = $this->option('batch');

        if ($option === null || $option === '') {
            $batch = (int) config('hbx.content.sync_batch_size');
        } elseif (is_int($option) || (is_string($option) && preg_match('/^\d+$/', $option) === 1)) {
            $batch = (int) $option;
        } else {
            throw new HbxValidationException(
                'Content sync batch must be a whole number from 1 to '.HbxContentHotelsService::MAX_SYNC_BATCH.'.',
                'INVALID_DATA',
                null,
                [],
                'content_hotels'
            );
        }

        if ($batch < 1 || $batch > HbxContentHotelsService::MAX_SYNC_BATCH) {
            throw new HbxValidationException(
                'Content sync batch must be a whole number from 1 to '.HbxContentHotelsService::MAX_SYNC_BATCH.'.',
                'INVALID_DATA',
                null,
                [],
                'content_hotels'
            );
        }

        return $batch;
    }

    private function wholeNumber(string $option, string $message): int
    {
        $value = $this->option($option);

        if (! is_string($value) && ! is_int($value)) {
            throw new HbxValidationException($message, 'INVALID_DATA', null, [], 'content_hotels');
        }

        $value = (string) $value;

        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            throw new HbxValidationException($message, 'INVALID_DATA', null, [], 'content_hotels');
        }

        return (int) $value;
    }

    private function optionalWholeNumber(string $option, string $message): ?int
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new HbxValidationException($message, 'INVALID_DATA', null, [], 'content_hotels');
        }

        $value = (string) $value;

        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            throw new HbxValidationException($message, 'INVALID_DATA', null, [], 'content_hotels');
        }

        return (int) $value;
    }
}
