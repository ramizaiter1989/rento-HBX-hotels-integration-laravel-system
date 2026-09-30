<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentSyncRun;
use Illuminate\Console\Command;

class HbxContentSyncStopCommand extends Command
{
    protected $signature = 'hbx:content:sync-stop {--id= : Running sync run to stop after the current page}';

    protected $description = 'Ask a running HBX content sync to stop after the current page';

    public function handle(): int
    {
        $id = $this->option('id');
        $run = ContentSyncRun::query()
            ->where('status', ContentSyncRun::RUNNING)
            ->when(is_numeric($id), fn ($query) => $query->whereKey((int) $id))
            ->orderByDesc('id')
            ->first();

        if ($run === null) {
            $this->error('No running content sync.');

            return self::FAILURE;
        }

        $run->forceFill(['stop_requested' => true])->save();
        $this->line('Stop requested for run '.$run->id.'. It will stop after the current page.');

        return self::SUCCESS;
    }
}
