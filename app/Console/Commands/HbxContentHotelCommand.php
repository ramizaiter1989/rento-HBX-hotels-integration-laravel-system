<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HBX\HbxContentHotelImporter;
use App\Services\HBX\HbxContentHotelService;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class HbxContentHotelCommand extends Command
{
    protected $signature = 'hbx:content:hotel {hotelCode : HBX hotel code} {--language=ENG : Content language code} {--import : Store the hotel in the local content tables}';

    protected $description = 'Retrieve HBX Hotels Content API details for one hotel';

    public function handle(HbxContentHotelService $content, HbxContentHotelImporter $importer): int
    {
        $hotelCode = (int) $this->argument('hotelCode');
        $language = (string) $this->option('language');

        if (! $this->option('import')) {
            $this->line('HBX environment: '.config('hbx.environment'));
            $this->line('Base URL: '.config('hbx.base_url'));
            $this->line('Hotel code: '.$hotelCode);
            $this->line('Language: '.strtoupper(trim($language)));
        }

        try {
            $result = $content->getHotel($hotelCode, $language);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            if (property_exists($exception, 'supplierCode') && $exception->supplierCode) {
                $this->line('Supplier code: '.$exception->supplierCode);
            }

            return self::FAILURE;
        }

        if ($this->option('import')) {
            try {
                $outcome = $importer->import($result, $language, $hotelCode);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                if (property_exists($exception, 'supplierCode') && $exception->supplierCode) {
                    $this->line('Supplier code: '.$exception->supplierCode);
                }

                return self::FAILURE;
            }

            $this->line('Hotel: '.$outcome->hotelCode);
            $this->line('Language: '.$outcome->language);
            $this->line('Rooms: '.$outcome->rooms);
            $this->line('Images: '.$outcome->images);
            $this->line('Facilities: '.$outcome->facilities);
            $this->line('Snapshot bytes: '.$outcome->snapshotBytes);
            $this->line('Hash: '.substr($outcome->hash, 0, 12));
            $this->line('Result: '.$outcome->status);

            foreach ($outcome->conflicts as $conflict) {
                $this->line('Conflict: '.$conflict);
            }

            foreach ($outcome->unmatchedWildcards as $code) {
                $this->line('Unmatched wildcard: '.$code);
            }

            return self::SUCCESS;
        }

        $this->info('HTTP status: '.$result->httpStatus);
        $this->line('Local duration ms: '.$result->durationMs);
        $this->line('HBX processTime: '.($result->processTime ?? '—'));
        $this->line('HBX timestamp: '.($result->supplierTimestamp ?? '—'));
        $this->line('Content hotel code: '.($this->contentHotelCode($result->data) ?? '—'));
        $this->line('Content hotel name: '.($this->contentHotelName($result->data) ?? '—'));
        $this->newLine();
        $this->line($this->prettyJson($result->rawBody));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function contentHotelCode(array $data): ?string
    {
        $hotel = is_array($data['hotel'] ?? null) ? $data['hotel'] : [];
        $code = $hotel['code'] ?? null;

        return is_scalar($code) && (string) $code !== '' ? (string) $code : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function contentHotelName(array $data): ?string
    {
        $hotel = is_array($data['hotel'] ?? null) ? $data['hotel'] : [];
        $name = $hotel['name'] ?? null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        if (is_array($name) && is_string($name['content'] ?? null) && $name['content'] !== '') {
            return $name['content'];
        }

        return null;
    }

    private function prettyJson(string $raw): string
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $raw;
        }

        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($pretty) ? $pretty : $raw;
    }
}
