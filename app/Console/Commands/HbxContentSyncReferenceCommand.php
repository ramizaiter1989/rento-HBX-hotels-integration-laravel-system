<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentSyncRun;
use App\Services\HBX\HbxContentReferenceSyncService;
use App\Support\PositiveConfigInt;
use Illuminate\Console\Command;
use Throwable;

class HbxContentSyncReferenceCommand extends Command
{
    protected $signature = 'hbx:content:sync-reference
        {--language=ENG : Content language, for example ENG}
        {--type=all : One catalog, or all}
        {--batch= : Page size, 1 to 1000}';

    protected $description = 'Import HBX Content reference catalogs. GET only, idempotent, and safe to replay.';

    public function handle(HbxContentReferenceSyncService $sync): int
    {
        $language = strtoupper((string) $this->option('language'));

        if (preg_match('/^[A-Z]{3}$/', $language) !== 1) {
            $this->error('Reference language must be a 3-letter code such as ENG.');

            return self::FAILURE;
        }
        $requested = strtolower(trim((string) $this->option('type')));
        $batch = $this->option('batch');
        $batch = $batch === null || $batch === ''
            ? PositiveConfigInt::from(config('hbx.content.reference_batch_size'), 100)
            : (int) $batch;

        $types = $requested === '' || $requested === 'all'
            ? HbxContentReferenceSyncService::TYPES
            : [$sync->canonicalType($requested)];

        if ($requested === 'zones') {
            $this->line('Zones have no standalone HBX endpoint. They are stored from the destinations payload.');
        }

        if (in_array($requested, ['room-types', 'room-characteristics'], true)) {
            $this->line('Room type and characteristic codes come from the rooms catalog. HBX does not publish separate description endpoints for them.');
        }

        $totals = [
            'fetched' => 0,
            'imported' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'http_requests' => 0,
        ];
        $run = ContentSyncRun::query()->create([
            'sync_type' => ContentSyncRun::REFERENCE,
            'language' => $language,
            'batch_size' => $batch,
            'next_from' => 1,
            'status' => ContentSyncRun::RUNNING,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);

        try {
            foreach ($types as $type) {
                $counts = $sync->sync($type, $language, $batch);

                foreach ($totals as $key => $value) {
                    $totals[$key] += $counts[$key];
                }

                $this->line(strtoupper($type));
                $this->line('Fetched: '.$counts['fetched']);
                $this->line('Imported: '.$counts['imported']);
                $this->line('Updated: '.$counts['updated']);
                $this->line('Unchanged: '.$counts['unchanged']);
                $this->line('Failed: '.$counts['failed']);
            }
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => ContentSyncRun::FAILED,
                'stop_reason' => mb_substr($exception->getMessage(), 0, 500),
                'completed_at' => now(),
                'last_progress_at' => now(),
            ] + $totals)->save();

            throw $exception;
        }

        $run->forceFill([
            'status' => $totals['failed'] > 0 ? ContentSyncRun::FAILED : ContentSyncRun::COMPLETED,
            'completed_at' => now(),
            'last_progress_at' => now(),
        ] + $totals)->save();

        $this->newLine();
        $this->line('Reference sync finished.');
        $this->line('HTTP requests: '.$totals['http_requests']);

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
