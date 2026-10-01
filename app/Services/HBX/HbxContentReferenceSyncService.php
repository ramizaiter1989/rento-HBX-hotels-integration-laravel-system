<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\Exceptions\HBX\HbxValidationException;
use Throwable;

/**
 * GET-only import of HBX Content reference catalogs.
 * Replaying a catalog upserts by supplier identity.
 */
final class HbxContentReferenceSyncService
{
    public const MAX_BATCH = 1000;

    private const MAX_PAGES = 500;

    /**
     * Catalogs that have their own HBX endpoint, in a controlled sequence.
     * Zones are nested in destinations. Room type and characteristic codes are
     * extracted from the rooms catalog. Those are not separate endpoints.
     *
     * @var list<string>
     */
    public const TYPES = [
        'facility-groups',
        'facilities',
        'rooms',
        'categories',
        'category-groups',
        'chains',
        'accommodations',
        'boards',
        'segments',
        'image-types',
        'countries',
        'destinations',
    ];

    public function __construct(
        private readonly HbxClient $client,
        private readonly ContentReferenceStore $store,
    ) {}

    /**
     * @return array{fetched: int, imported: int, updated: int, unchanged: int, failed: int, http_requests: int}
     */
    public function sync(string $type, string $language, int $batch): array
    {
        $type = $this->canonicalType($type);
        $language = $this->language($language);
        $batch = $this->batch($batch);
        $endpoint = (string) config('hbx.endpoints.reference.'.$type);
        $counts = [
            'fetched' => 0,
            'imported' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'http_requests' => 0,
        ];
        $from = 1;
        $seen = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            if (isset($seen[$from])) {
                break;
            }

            $seen[$from] = true;
            $to = $from + $batch - 1;
            $result = $this->client->get($endpoint, [
                'fields' => 'all',
                'language' => $language,
                'from' => $from,
                'to' => $to,
                'useSecondaryLanguage' => 'false',
            ], 'content_reference');
            $counts['http_requests']++;
            $data = $result->data;
            $rows = $this->rows($type, $data);

            foreach ($rows as $row) {
                $counts['fetched']++;

                try {
                    $this->importRow($type, $row, $language, $counts);
                } catch (Throwable) {
                    $counts['failed']++;
                }
            }

            $total = $this->integer($data['total'] ?? null);
            $responseTo = $this->integer($data['to'] ?? null);
            $next = ($responseTo ?? ($from + count($rows) - 1)) + 1;

            if ($rows === [] || $next <= $from || ($total !== null && ($responseTo ?? $to) >= $total) || count($rows) < $batch) {
                break;
            }

            $from = $next;
        }

        return $counts;
    }

    public function canonicalType(string $type): string
    {
        $type = strtolower(trim($type));

        if (in_array($type, ['zones', 'zone'], true)) {
            return 'destinations';
        }

        if (in_array($type, ['room-types', 'room-characteristics', 'room-type', 'room-characteristic'], true)) {
            return 'rooms';
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new HbxValidationException(
                'Unknown reference type. Supported types: '.implode(', ', self::TYPES).'.',
                'INVALID_REFERENCE_TYPE',
                null,
                [],
                'content_reference'
            );
        }

        return $type;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $row
     */
    private function importRow(string $type, array $row, string $language, array &$counts): void
    {
        match ($type) {
            'facilities' => $this->importFacility($row, $language, $counts),
            'rooms' => $this->importRoom($row, $language, $counts),
            'destinations' => $this->importDestination($row, $language, $counts),
            'categories' => $this->count($counts, $this->store->upsert('categories', [
                'code' => $this->requireCode($row),
            ], $language, $this->description($row), [
                'category_group_code' => $this->text($row['group'] ?? $row['categoryGroupCode'] ?? null),
            ])),
            default => $this->count($counts, $this->store->upsert($type, [
                'code' => $this->requireCode($row),
            ], $language, $this->description($row))),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $counts
     */
    private function importFacility(array $row, string $language, array &$counts): void
    {
        $code = $this->integer($row['code'] ?? null);
        $group = $this->integer($row['facilityGroupCode'] ?? null);

        if ($code === null || $group === null) {
            $counts['failed']++;

            return;
        }

        $this->count($counts, $this->store->upsert('facilities', [
            'code' => $code,
            'facility_group_code' => $group,
        ], $language, $this->description($row), [
            'facility_typology_code' => $this->integer($row['facilityTypologyCode'] ?? null),
        ]));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $counts
     */
    private function importRoom(array $row, string $language, array &$counts): void
    {
        $code = $this->text($row['code'] ?? null);

        if ($code === null) {
            $counts['failed']++;

            return;
        }

        $type = $this->text($row['type'] ?? null);
        $characteristic = $this->text($row['characteristic'] ?? null);

        if ($type !== null) {
            $this->count($counts, $this->store->upsert('room-types', [
                'code' => $type,
            ], $language, $this->text($row['typeDescription'] ?? null)));
        }

        if ($characteristic !== null) {
            $this->count($counts, $this->store->upsert('room-characteristics', [
                'code' => $characteristic,
            ], $language, $this->text($row['characteristicDescription'] ?? null)));
        }

        $this->count($counts, $this->store->upsert('rooms', [
            'code' => $code,
        ], $language, $this->description($row), array_filter([
            'type_code' => $type,
            'characteristic_code' => $characteristic,
            'min_pax' => $this->integer($row['minPax'] ?? null),
            'max_pax' => $this->integer($row['maxPax'] ?? null),
            'max_adults' => $this->integer($row['maxAdults'] ?? null),
            'max_children' => $this->integer($row['maxChildren'] ?? null),
            'min_adults' => $this->integer($row['minAdults'] ?? null),
        ], fn (mixed $value): bool => $value !== null)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $counts
     */
    private function importDestination(array $row, string $language, array &$counts): void
    {
        $code = $this->text($row['code'] ?? null);

        if ($code === null) {
            $counts['failed']++;

            return;
        }

        $this->count($counts, $this->store->upsert('destinations', [
            'code' => $code,
        ], $language, $this->description($row), [
            'country_code' => $this->text($row['countryCode'] ?? $row['isoCode'] ?? null),
        ]));

        $zones = is_array($row['zones'] ?? null) ? $row['zones'] : [];

        foreach ($zones as $zone) {
            if (! is_array($zone)) {
                continue;
            }

            $zoneCode = $this->integer($zone['zoneCode'] ?? $zone['code'] ?? null);

            if ($zoneCode === null) {
                continue;
            }

            $counts['fetched']++;
            $this->count($counts, $this->store->upsert('zones', [
                'destination_code' => $code,
                'zone_code' => $zoneCode,
            ], $language, $this->description($zone) ?? $this->text($zone['name'] ?? null)));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function rows(string $type, array $data): array
    {
        $keys = match ($type) {
            'facility-groups' => ['facilityGroups', 'facilitygroups'],
            'facilities' => ['facilities'],
            'rooms' => ['rooms'],
            'categories' => ['categories'],
            'category-groups' => ['groupCategories', 'groupcategories'],
            'chains' => ['chains'],
            'accommodations' => ['accommodations'],
            'boards' => ['boards'],
            'segments' => ['segments'],
            'image-types' => ['imageTypes', 'imagetypes'],
            'countries' => ['countries'],
            'destinations' => ['destinations'],
            default => [],
        };

        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }

        throw new HbxValidationException(
            'Reference payload did not include a recognized '.$type.' list.',
            'INVALID_DATA',
            null,
            [],
            'content_reference'
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function description(array $row): ?string
    {
        foreach (['description', 'name', 'typeMultiDescription'] as $key) {
            $text = $this->text($row[$key] ?? null);

            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return $this->text($value['content'] ?? null);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireCode(array $row): int|string
    {
        $code = $row['code'] ?? null;

        if (is_int($code)) {
            return $code;
        }

        if (is_string($code) && trim($code) !== '') {
            return trim($code);
        }

        if (is_numeric($code)) {
            return (int) $code;
        }

        throw new HbxValidationException(
            'Reference row is missing a supplier code.',
            'INVALID_DATA',
            null,
            [],
            'content_reference'
        );
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
     * @param  array<string, int>  $counts
     */
    private function count(array &$counts, string $status): void
    {
        if (! isset($counts[$status])) {
            $counts['failed']++;

            return;
        }

        $counts[$status]++;
    }

    private function language(string $language): string
    {
        $language = strtoupper(trim($language));

        if (preg_match('/^[A-Z]{3}$/', $language) !== 1) {
            throw new HbxValidationException(
                'Reference language must be a 3-letter code such as ENG.',
                'INVALID_LANGUAGE',
                null,
                [],
                'content_reference'
            );
        }

        return $language;
    }

    private function batch(int $batch): int
    {
        if ($batch < 1 || $batch > self::MAX_BATCH) {
            throw new HbxValidationException(
                'Reference batch must be between 1 and '.self::MAX_BATCH.'.',
                'INVALID_BATCH',
                null,
                [],
                'content_reference'
            );
        }

        return $batch;
    }
}
