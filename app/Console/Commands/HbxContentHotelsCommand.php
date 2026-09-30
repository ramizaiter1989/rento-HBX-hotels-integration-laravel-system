<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HBX\HbxContentHotelsService;
use Illuminate\Console\Command;
use Throwable;

class HbxContentHotelsCommand extends Command
{
    protected $signature = 'hbx:content:hotels {--from=1 : First hotel index, starting at 1} {--to=10 : Last hotel index} {--language=ENG : Content language code} {--last-update= : Send lastUpdateTime as YYYY-MM-DD}';

    protected $description = 'Retrieve one tiny page from the HBX Hotels Content API without importing it';

    public function handle(HbxContentHotelsService $content): int
    {
        $from = (int) $this->option('from');
        $to = (int) $this->option('to');
        $language = strtoupper(trim((string) $this->option('language')));
        $lastUpdate = $this->option('last-update');
        $lastUpdate = is_string($lastUpdate) ? trim($lastUpdate) : null;
        $lastUpdate = $lastUpdate === '' ? null : $lastUpdate;

        $this->line('HBX environment: '.config('hbx.environment'));
        $this->line('Base URL: '.config('hbx.base_url'));
        $this->line('Language: '.($language === '' ? 'ENG' : $language));
        $this->line('Range: '.$from.'-'.$to);

        try {
            $result = $content->page($from, $to, $language, $lastUpdate);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            if (property_exists($exception, 'supplierCode') && $exception->supplierCode) {
                $this->line('Supplier code: '.$exception->supplierCode);
            }

            return self::FAILURE;
        }

        $summary = $content->summarize($result);

        $this->info('HTTP status: '.$result->httpStatus);
        $this->line('HBX processTime: '.($result->processTime ?? '—'));
        $this->line('Response bytes: '.strlen($result->rawBody));
        $this->line('Hotels returned: '.$summary['hotelsReturned']);
        $this->line('Pagination: '.($summary['pagination'] === [] ? 'none' : $this->pairs($summary['pagination'])));
        $this->line('Total: '.($summary['total'] ?? 'not supplied'));
        $this->line('Top-level keys: '.implode(', ', $summary['topLevelKeys']));
        $this->line('Hotel 712 in range: '.($summary['hotel712'] ? 'yes' : 'no'));
        $this->line('lastUpdateTime query: '.($lastUpdate ?? 'not sent'));
        $this->line('lastUpdate values: '.($summary['lastUpdateValues'] === [] ? 'not present' : implode(', ', $summary['lastUpdateValues'])));
        $this->line('lastUpdateTime values: '.($summary['lastUpdateTimeValues'] === [] ? 'not present' : implode(', ', $summary['lastUpdateTimeValues'])));
        $this->line('Hotel key sets match: '.($summary['hotelsShareKeys'] ? 'yes' : 'no'));
        $this->line('Keys missing vs Hotel Details: '.($summary['missingVsDetails'] === [] ? 'none' : implode(', ', $summary['missingVsDetails'])));
        $this->line('Keys added vs Hotel Details: '.($summary['addedVsDetails'] === [] ? 'none' : implode(', ', $summary['addedVsDetails'])));

        foreach ($summary['names'] as $hotel) {
            $this->line($hotel['code'].' '.$hotel['name']);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $pairs
     */
    private function pairs(array $pairs): string
    {
        $rendered = [];

        foreach ($pairs as $key => $value) {
            $rendered[] = $key.'='.$value;
        }

        return implode(', ', $rendered);
    }
}
