<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentSyncRun;
use Illuminate\Console\Command;

class HbxContentSyncAbandonCommand extends Command
{
    protected $signature = 'hbx:content:sync-abandon {run : Sync run id to close without deleting it}';

    protected $description = 'Close a resumable content sync so it cannot be resumed. Statistics stay stored.';

    public function handle(): int
    {
        $id = $this->argument('run');

        if (! is_string($id) || preg_match('/^[1-9]\d*$/', $id) !== 1) {
            $this->error('Content sync run id must be a positive whole number.');

            return self::FAILURE;
        }

        $run = ContentSyncRun::query()->find((int) $id);

        if ($run === null) {
            $this->error('Content sync run '.$id.' was not found.');

            return self::FAILURE;
        }

        if (! $run->isResumable()) {
            $this->error('Content sync run '.$run->id.' is '.$run->status.' and cannot be abandoned.');

            return self::FAILURE;
        }

        $run->forceFill([
            'status' => ContentSyncRun::ABANDONED,
        ])->save();

        $this->line('Abandoned run '.$run->id.'.');
        $this->line('Status: '.$run->status);
        $this->line('Fetched total: '.$run->fetched);
        $this->line('Next from: '.$run->next_from);
        $this->line('Requested target: '.($run->requested_limit === null ? 'none' : (string) $run->requested_limit));

        return self::SUCCESS;
    }
}
