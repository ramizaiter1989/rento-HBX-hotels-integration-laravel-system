<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\Models\HotelSearch;
use App\Support\DecimalString;
use App\Support\JsonCursor;
use App\Support\JsonDecimals;

/**
 * Reads one stored availability snapshot without decoding every hotel.
 */
final class AvailabilitySnapshotReader
{
    public const PER_PAGE = 20;

    public function __construct(private readonly AvailabilityResultReader $results) {}

    /**
     * @return array{
     *     check_in: ?string,
     *     check_out: ?string,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     hotels: list<array<string, mixed>>
     * }
     */
    public function getHotelSummaries(HotelSearch $search, int $page, int $perPage = self::PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = min(self::PER_PAGE, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $json = $this->payload($search);
        $hotels = [];
        $seen = 0;
        $finished = true;

        $meta = $this->walkHotels($json, function (int $start, int $end) use ($json, $offset, $perPage, &$hotels, &$seen, &$finished): bool {
            $index = $seen;
            $seen++;

            if ($index < $offset) {
                return true;
            }

            if (count($hotels) >= $perPage) {
                $finished = false;

                return false;
            }

            $hotels[] = $this->summarize($json, $start);

            return true;
        });

        $total = $meta['total'] ?? ($finished ? $seen : (int) $search->hotels_returned);

        return [
            'check_in' => $meta['check_in'],
            'check_out' => $meta['check_out'],
            'total' => max(0, $total),
            'page' => $page,
            'per_page' => $perPage,
            'hotels' => $hotels,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getHotel(HotelSearch $search, string|int $hotelCode): ?array
    {
        $json = $this->hotelSlice($search, $hotelCode);

        if ($json === null) {
            return null;
        }

        $hotel = JsonDecimals::decode($json);

        return $hotel === [] ? null : $hotel;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRoomsForHotel(HotelSearch $search, string|int $hotelCode): ?array
    {
        $hotel = $this->getHotel($search, $hotelCode);

        if ($hotel === null) {
            return null;
        }

        $presented = $this->results->present([
            'hotels' => [
                'hotels' => [$hotel],
            ],
        ]);

        return $presented['hotels'][0] ?? null;
    }

    public function getRawResponse(HotelSearch $search): string
    {
        return $this->payload($search);
    }

    /**
     * @return array{hotel: array<string, mixed>, room: array<string, mixed>, rate: array<string, mixed>}|null
     */
    public function findRate(HotelSearch $search, string $rateKey): ?array
    {
        $json = $this->payload($search);
        $slice = null;

        $this->walkHotels($json, function (int $start, int $end) use ($json, $rateKey, &$slice): bool {
            if (! $this->hotelHasRateKey($json, $start, $rateKey)) {
                return true;
            }

            $slice = substr($json, $start, $end - $start);

            return false;
        });

        if (! is_string($slice) || $slice === '') {
            return null;
        }

        $hotel = JsonDecimals::decode($slice);

        return $this->results->findRate([
            'hotels' => [
                'hotels' => [$hotel],
            ],
        ], $rateKey);
    }

    private function payload(HotelSearch $search): string
    {
        $payload = $search->response_payload;

        return is_string($payload) ? $payload : '';
    }

    private function hotelSlice(HotelSearch $search, string|int $hotelCode): ?string
    {
        $json = $this->payload($search);
        $wanted = (string) $hotelCode;
        $slice = null;

        $this->walkHotels($json, function (int $start, int $end) use ($json, $wanted, &$slice): bool {
            $summary = $this->summarize($json, $start);

            if ((string) ($summary['code'] ?? '') !== $wanted) {
                return true;
            }

            $slice = substr($json, $start, $end - $start);

            return false;
        });

        return $slice;
    }

    /**
     * @param  callable(int, int): bool  $onHotel
     * @return array{check_in: ?string, check_out: ?string, total: ?int}
     */
    private function walkHotels(string $json, callable $onHotel): array
    {
        $meta = [
            'check_in' => null,
            'check_out' => null,
            'total' => null,
        ];

        if ($json === '') {
            return $meta;
        }

        $cursor = new JsonCursor($json);
        $cursor->eachKey(function (string $key) use ($cursor, $onHotel, &$meta): void {
            if ($key !== 'hotels') {
                $cursor->skipValue();

                return;
            }

            $this->readHotelsNode($cursor, $onHotel, $meta);
        });

        return $meta;
    }

    /**
     * @param  callable(int, int): bool  $onHotel
     * @param  array{check_in: ?string, check_out: ?string, total: ?int}  $meta
     */
    private function readHotelsNode(JsonCursor $cursor, callable $onHotel, array &$meta): void
    {
        if ($cursor->peek() === '[') {
            $this->eachHotel($cursor, $onHotel);

            return;
        }

        if ($cursor->peek() !== '{') {
            $cursor->skipValue();

            return;
        }

        $cursor->eachKey(function (string $key) use ($cursor, $onHotel, &$meta): void {
            if ($key === 'checkIn') {
                $meta['check_in'] = $this->text($cursor->readScalar());

                return;
            }

            if ($key === 'checkOut') {
                $meta['check_out'] = $this->text($cursor->readScalar());

                return;
            }

            if ($key === 'total') {
                $value = $cursor->readScalar();
                $meta['total'] = is_numeric($value) ? (int) $value : null;

                return;
            }

            if ($key === 'hotels' && $cursor->peek() === '[') {
                $this->eachHotel($cursor, $onHotel);

                return;
            }

            $cursor->skipValue();
        });
    }

    /**
     * @param  callable(int, int): bool  $onHotel
     */
    private function eachHotel(JsonCursor $cursor, callable $onHotel): void
    {
        $cursor->eachElement(function () use ($cursor, $onHotel): bool {
            $start = $cursor->position();
            $cursor->skipValue();
            $end = $cursor->position();

            return $onHotel($start, $end);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(string $json, int $start): array
    {
        $fields = [];
        $cursor = new JsonCursor($json);
        $cursor->seek($start);
        $cursor->eachKey(function (string $key) use ($cursor, &$fields): void {
            if (! in_array($key, [
                'code',
                'name',
                'categoryName',
                'categoryCode',
                'destinationCode',
                'destinationName',
                'zoneName',
                'latitude',
                'longitude',
                'minRate',
                'maxRate',
                'currency',
            ], true)) {
                $cursor->skipValue();

                return;
            }

            $fields[$key] = $cursor->readScalar();
        });

        return [
            'code' => isset($fields['code']) ? (string) $fields['code'] : null,
            'name' => $this->text($fields['name'] ?? null),
            'category' => $this->text($fields['categoryName'] ?? $fields['categoryCode'] ?? null),
            'destination_code' => $this->text($fields['destinationCode'] ?? null),
            'destination_name' => $this->text($fields['destinationName'] ?? null),
            'zone' => $this->text($fields['zoneName'] ?? null),
            'latitude' => $this->text($fields['latitude'] ?? null),
            'longitude' => $this->text($fields['longitude'] ?? null),
            'min_rate' => DecimalString::from($fields['minRate'] ?? null),
            'max_rate' => DecimalString::from($fields['maxRate'] ?? null),
            'currency' => $this->text($fields['currency'] ?? null),
        ];
    }

    private function hotelHasRateKey(string $json, int $start, string $rateKey): bool
    {
        $cursor = new JsonCursor($json);
        $cursor->seek($start);

        return $this->valueHasRateKey($cursor, $rateKey);
    }

    private function valueHasRateKey(JsonCursor $cursor, string $rateKey): bool
    {
        $char = $cursor->peek();

        if ($char === '{') {
            $found = false;
            $cursor->eachKey(function (string $key) use ($cursor, $rateKey, &$found): void {
                if ($found) {
                    $cursor->skipValue();

                    return;
                }

                if ($key === 'rateKey') {
                    $found = $cursor->readScalar() === $rateKey;

                    return;
                }

                $found = $this->valueHasRateKey($cursor, $rateKey);
            });

            return $found;
        }

        if ($char === '[') {
            $found = false;
            $cursor->eachElement(function () use ($cursor, $rateKey, &$found): void {
                if ($found) {
                    $cursor->skipValue();

                    return;
                }

                $found = $this->valueHasRateKey($cursor, $rateKey);
            });

            return $found;
        }

        $cursor->skipValue();

        return false;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value) || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
