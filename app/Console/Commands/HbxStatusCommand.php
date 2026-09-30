<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HBX\HbxStatusService;
use Illuminate\Console\Command;
use Throwable;

class HbxStatusCommand extends Command
{
    protected $signature = 'hbx:status';

    protected $description = 'Call the HBX TEST status endpoint and print a sanitized result';

    public function handle(HbxStatusService $status): int
    {
        $this->line('HBX environment: '.config('hbx.environment'));
        $this->line('Base URL: '.config('hbx.base_url'));

        try {
            $result = $status->check();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            if (property_exists($exception, 'supplierCode') && $exception->supplierCode) {
                $this->line('Supplier code: '.$exception->supplierCode);
            }

            return self::FAILURE;
        }

        $this->info('HTTP status: '.$result->httpStatus);
        $this->info('HBX status: '.($result->data['status'] ?? 'unknown'));
        $this->line('HBX timestamp: '.($result->supplierTimestamp ?? '—'));
        $this->line('HBX processTime: '.($result->processTime ?? '—'));
        $this->line('Local duration ms: '.$result->durationMs);

        return ($result->data['status'] ?? null) === 'OK' ? self::SUCCESS : self::FAILURE;
    }
}
