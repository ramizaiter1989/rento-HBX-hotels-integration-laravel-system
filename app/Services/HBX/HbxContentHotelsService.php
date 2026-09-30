<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\DTOs\HBX\HbxResult;
use App\Exceptions\HBX\HbxValidationException;
use DateTimeImmutable;

final class HbxContentHotelsService
{
    public const MAX_PAGE_SIZE = 10;

    public const MAX_SYNC_BATCH = 100;

    /**
     * Hotel object keys observed on the stored Hotel Details response for hotel 712.
     * Used only to compare a Hotels list page with that known details shape.
     *
     * @var list<string>
     */
    public const HOTEL_DETAILS_KEYS = [
        'S2C',
        'accommodationType',
        'address',
        'boards',
        'category',
        'categoryGroup',
        'chain',
        'city',
        'code',
        'coordinates',
        'country',
        'description',
        'destination',
        'email',
        'facilities',
        'giataCode',
        'images',
        'interestPoints',
        'lastUpdate',
        'license',
        'name',
        'phones',
        'postalCode',
        'ranking',
        'rooms',
        'segments',
        'state',
        'terminals',
        'web',
        'wildcards',
        'zone',
    ];

    public function __construct(private readonly HbxClient $client) {}

    public function page(int $from = 1, int $to = 10, string $language = 'ENG', ?string $lastUpdateTime = null): HbxResult
    {
        return $this->requestPage(
            $from,
            $to,
            $language,
            $lastUpdateTime,
            self::MAX_PAGE_SIZE,
            'Hotels discovery is limited to '.self::MAX_PAGE_SIZE.' hotels per request.'
        );
    }

    public function catalogPage(int $from, int $to, string $language = 'ENG', ?string $lastUpdateTime = null): HbxResult
    {
        return $this->requestPage(
            $from,
            $to,
            $language,
            $lastUpdateTime,
            self::MAX_SYNC_BATCH,
            'Content sync is limited to '.self::MAX_SYNC_BATCH.' hotels per request.'
        );
    }

    private function requestPage(int $from, int $to, string $language, ?string $lastUpdateTime, int $maxHotels, string $limitMessage): HbxResult
    {
        if ($from < 1 || $to < $from) {
            throw new HbxValidationException(
                'Hotels page range must start at 1 or later, and to must not precede from.',
                'INVALID_DATA',
                null,
                [],
                'content_hotels'
            );
        }

        if (($to - $from + 1) > $maxHotels) {
            throw new HbxValidationException(
                $limitMessage,
                'INVALID_DATA',
                null,
                [],
                'content_hotels'
            );
        }

        $language = strtoupper(trim($language));

        if ($language === '') {
            $language = 'ENG';
        }

        $query = [
            'fields' => 'all',
            'language' => $language,
            'from' => (string) $from,
            'to' => (string) $to,
            'useSecondaryLanguage' => 'false',
        ];
        $lastUpdateTime = $this->lastUpdateTime($lastUpdateTime);

        if ($lastUpdateTime !== null) {
            $query['lastUpdateTime'] = $lastUpdateTime;
        }

        return $this->client->get(
            (string) config('hbx.endpoints.content_hotels'),
            $query,
            'content_hotels'
        );
    }

    public function lastUpdateTime(?string $lastUpdateTime): ?string
    {
        if ($lastUpdateTime === null) {
            return null;
        }

        $lastUpdateTime = trim($lastUpdateTime);

        if ($lastUpdateTime === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $lastUpdateTime);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || $parsed->format('Y-m-d') !== $lastUpdateTime
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        ) {
            throw new HbxValidationException(
                'lastUpdateTime must be a calendar date in YYYY-MM-DD form.',
                'INVALID_DATA',
                null,
                [],
                'content_hotels'
            );
        }

        return $lastUpdateTime;
    }

    /**
     * @return array{
     *     hotelsReturned: int,
     *     pagination: array<string, string>,
     *     total: ?string,
     *     topLevelKeys: list<string>,
     *     hotel712: bool,
     *     names: list<array{code: string, name: string}>,
     *     missingVsDetails: list<string>,
     *     addedVsDetails: list<string>,
     *     hotelsShareKeys: bool,
     *     lastUpdateValues: list<string>,
     *     lastUpdateTimeValues: list<string>
     * }
     */
    public function summarize(HbxResult $result): array
    {
        $hotels = $this->hotels($result->data);
        $pagination = [];

        foreach ($result->data as $key => $value) {
            if (! is_string($key) || in_array($key, ['auditData', 'hotels'], true)) {
                continue;
            }

            $pagination[$key] = $this->scalarLabel($value);
        }

        ksort($pagination);

        $keySets = [];

        foreach ($hotels as $hotel) {
            $keys = array_keys($hotel);
            sort($keys);
            $keySets[] = $keys;
        }

        $union = $keySets === [] ? [] : array_values(array_unique(array_merge(...$keySets)));
        sort($union);
        $shared = $keySets === [] ? true : count(array_unique(array_map(
            fn (array $keys): string => implode("\0", $keys),
            $keySets
        ))) === 1;

        $names = [];

        foreach (array_slice($hotels, 0, self::MAX_PAGE_SIZE) as $hotel) {
            $names[] = [
                'code' => $this->code($hotel),
                'name' => $this->name($hotel),
            ];
        }

        return [
            'hotelsReturned' => count($hotels),
            'pagination' => $pagination,
            'total' => $this->total($result->data),
            'topLevelKeys' => $this->topLevelKeys($result->data),
            'hotel712' => $this->containsHotel($hotels, 712),
            'names' => $names,
            'missingVsDetails' => array_values(array_diff(self::HOTEL_DETAILS_KEYS, $union)),
            'addedVsDetails' => array_values(array_diff($union, self::HOTEL_DETAILS_KEYS)),
            'hotelsShareKeys' => $shared,
            'lastUpdateValues' => $this->hotelScalarValues($hotels, 'lastUpdate'),
            'lastUpdateTimeValues' => array_values(array_unique([
                ...$this->topScalarValues($result->data, 'lastUpdateTime'),
                ...$this->hotelScalarValues($hotels, 'lastUpdateTime'),
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function hotels(array $data): array
    {
        $hotels = $data['hotels'] ?? null;

        if (! is_array($hotels)) {
            return [];
        }

        return array_values(array_filter($hotels, is_array(...)));
    }

    /**
     * @param  list<array<string, mixed>>  $hotels
     */
    private function containsHotel(array $hotels, int $code): bool
    {
        foreach ($hotels as $hotel) {
            if ($this->code($hotel) === (string) $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $hotel
     */
    private function code(array $hotel): string
    {
        $code = $hotel['code'] ?? null;

        return is_scalar($code) && (string) $code !== '' ? (string) $code : '—';
    }

    /**
     * @param  array<string, mixed>  $hotel
     */
    private function name(array $hotel): string
    {
        $name = $hotel['name'] ?? null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        if (is_array($name) && is_string($name['content'] ?? null) && $name['content'] !== '') {
            return $name['content'];
        }

        return '—';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function total(array $data): ?string
    {
        foreach (['total', 'totalHotels'] as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                return (string) $data[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function topLevelKeys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys);

        return array_map(strval(...), $keys);
    }

    /**
     * @param  list<array<string, mixed>>  $hotels
     * @return list<string>
     */
    private function hotelScalarValues(array $hotels, string $key): array
    {
        $values = [];

        foreach ($hotels as $hotel) {
            if (! array_key_exists($key, $hotel)) {
                continue;
            }

            $values[] = $this->scalarLabel($hotel[$key]);
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function topScalarValues(array $data, string $key): array
    {
        if (! array_key_exists($key, $data)) {
            return [];
        }

        return [$this->scalarLabel($data[$key])];
    }

    private function scalarLabel(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return array_is_list($value) ? 'list('.count($value).')' : 'object('.count($value).')';
        }

        return get_debug_type($value);
    }
}
