<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HbxApiLog;
use App\Models\HotelSearch;
use App\Support\PositiveConfigInt;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class HbxAvailabilityCleanupCommand extends Command
{
    protected $signature = 'hbx:availability:cleanup {--dry-run : Report counts without deleting}';

    protected $description = 'Delete unbooked availability snapshots and old availability logs past the debug retention window';

    public function handle(): int
    {
        $days = PositiveConfigInt::from(config('hbx.availability.retention_days'), 7);
        $cutoff = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $searchIds = HotelSearch::query()
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('bookings')
            ->pluck('id');

        $bookedKept = HotelSearch::query()
            ->where('created_at', '<', $cutoff)
            ->whereHas('bookings')
            ->count();

        $logIds = HbxApiLog::query()
            ->where('operation', 'availability')
            ->where('created_at', '<', $cutoff)
            ->pluck('id');

        $this->line('Retention days: '.$days);
        $this->line('Cutoff: '.$cutoff->toDateTimeString());
        $this->line('Unbooked hotel searches eligible: '.$searchIds->count());
        $this->line('Booked hotel searches kept: '.$bookedKept);
        $this->line('Availability logs eligible: '.$logIds->count());

        if ($dryRun) {
            $this->info('Dry run: nothing deleted.');

            return self::SUCCESS;
        }

        if ($searchIds->isNotEmpty()) {
            HotelSearch::query()->whereIn('id', $searchIds)->delete();
        }

        if ($logIds->isNotEmpty()) {
            HbxApiLog::query()->whereIn('id', $logIds)->delete();
        }

        $this->info('Unbooked hotel searches deleted: '.$searchIds->count());
        $this->info('Availability logs deleted: '.$logIds->count());

        return self::SUCCESS;
    }
}
