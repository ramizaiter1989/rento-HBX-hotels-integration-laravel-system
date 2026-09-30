<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentSyncRun;
use Illuminate\Console\Command;

class HbxContentSyncStatusCommand extends Command
{
    protected $signature = 'hbx:content:sync-status {--id= : Show one sync run}';

    protected $description = 'Show HBX content sync checkpoints';

    public function handle(): int
    {
        $id = $this->option('id');
        $runs = ContentSyncRun::query()
            ->when(is_numeric($id), fn ($query) => $query->whereKey((int) $id))
            ->orderByDesc('id')
            ->limit(is_numeric($id) ? 1 : 10)
            ->get();

        if ($runs->isEmpty()) {
            $this->line('No content sync runs.');

            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            $this->line('Run: '.$run->id);
            $this->line('Type: '.$run->sync_type);
            $this->line('Status: '.$run->status);
            $this->line('Language: '.$run->language);
            $this->line('lastUpdateTime: '.($run->last_update_time?->format('Y-m-d') ?? 'not sent'));
            $this->line('Batch: '.$run->batch_size);
            $this->line('Next from: '.$run->next_from);
            $this->line('Supplier total: '.($run->supplier_total ?? 'unknown'));
            $this->line('Fetched: '.$run->fetched);
            $this->line('Imported: '.$run->imported);
            $this->line('Updated: '.$run->updated);
            $this->line('Unchanged: '.$run->unchanged);
            $this->line('Failed: '.$run->failed);
            $this->line('Details retained: '.$run->details_retained);
            $this->line('Conflicts: '.$run->conflicts);
            $this->line('HTTP requests: '.$run->http_requests);
            $this->line('Stop requested: '.($run->stop_requested ? 'yes' : 'no'));
            $this->line('Started: '.$run->started_at?->format('Y-m-d H:i:s'));
            $this->line('Last progress: '.($run->last_progress_at?->format('Y-m-d H:i:s') ?? 'none'));
            $this->line('Completed: '.($run->completed_at?->format('Y-m-d H:i:s') ?? 'none'));

            if ($run->stop_reason) {
                $this->line('Reason: '.$run->stop_reason);
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
