<?php

declare(strict_types=1);

namespace App\DTOs\HBX;

use App\Exceptions\HBX\HbxValidationException;

final class AvailabilityQuery
{
    /**
     * @param  list<int>  $hotelCodes
     * @param  list<int>  $childAges
     */
    public function __construct(
        public readonly string $checkIn,
        public readonly string $checkOut,
        public readonly int $rooms,
        public readonly int $adults,
        public readonly int $children,
        public readonly array $childAges,
        public readonly ?string $destinationCode,
        public readonly array $hotelCodes,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $hotelCodes = self::hotelCodes($input['hotel_code'] ?? null);
        $destination = isset($input['destination_code']) ? strtoupper(trim((string) $input['destination_code'])) : '';
        $children = (int) ($input['children'] ?? 0);
        $ages = array_values(array_map('intval', $input['child_ages'] ?? []));

        return new self(
            checkIn: (string) $input['check_in'],
            checkOut: (string) $input['check_out'],
            rooms: (int) $input['rooms'],
            adults: (int) $input['adults'],
            children: $children,
            childAges: $children > 0 ? $ages : [],
            destinationCode: $destination !== '' ? $destination : null,
            hotelCodes: $hotelCodes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $this->assertSingleFilter();

        $occupancy = [
            'rooms' => $this->rooms,
            'adults' => $this->adults,
            'children' => $this->children,
        ];

        if ($this->children > 0) {
            $occupancy['paxes'] = array_map(
                fn (int $age): array => ['type' => 'CH', 'age' => $age],
                $this->childAges
            );
        }

        $payload = [
            'stay' => [
                'checkIn' => $this->checkIn,
                'checkOut' => $this->checkOut,
            ],
            'occupancies' => [$occupancy],
        ];

        if ($this->destinationCode !== null) {
            $payload['destination'] = ['code' => $this->destinationCode];
        } else {
            $payload['hotels'] = ['hotel' => $this->hotelCodes];
        }

        return $payload;
    }

    public function fingerprint(?string $environment = null): string
    {
        $environment = strtolower(trim($environment ?? (string) config('hbx.environment')));
        $destination = $this->destinationCode === null ? null : strtoupper(trim($this->destinationCode));

        if ($destination === '') {
            $destination = null;
        }

        $canonical = [
            'environment' => $environment,
            'checkIn' => $this->canonicalDate($this->checkIn),
            'checkOut' => $this->canonicalDate($this->checkOut),
            'rooms' => $this->rooms,
            'adults' => $this->adults,
            'children' => $this->children,
            'childAges' => $this->canonicalChildAges(),
            'destination' => $destination,
            'hotels' => $this->canonicalHotelCodes(),
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function assertSingleFilter(): void
    {
        $hasDestination = $this->destinationCode !== null && $this->destinationCode !== '';
        $hasHotels = $this->hotelCodes !== [];

        if ($hasDestination === $hasHotels) {
            throw new HbxValidationException(
                'The request must have one unique search filter: destination or hotels.',
                'INVALID_DATA',
                null,
                [],
                'availability'
            );
        }
    }

    /**
     * @return list<int>
     */
    private static function hotelCodes(mixed $value): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        $codes = [];

        foreach (preg_split('/\s*,\s*/', trim((string) $value)) ?: [] as $code) {
            if ($code === '' || ! preg_match('/^\d+$/', $code)) {
                throw new HbxValidationException(
                    'Hotel codes must be numeric HBX hotel codes.',
                    'INVALID_DATA',
                    null,
                    [],
                    'availability'
                );
            }

            $codes[] = (int) $code;
        }

        return $codes;
    }

    private function canonicalDate(string $value): string
    {
        $value = trim($value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $value) {
            return $value;
        }

        throw new HbxValidationException(
            'Check-in and check-out must be valid dates.',
            'INVALID_DATA',
            null,
            [],
            'availability'
        );
    }

    /**
     * @return list<int>
     */
    private function canonicalHotelCodes(): array
    {
        $codes = array_map(static fn (mixed $code): int => (int) $code, $this->hotelCodes);
        $codes = array_values(array_unique($codes));
        sort($codes, SORT_NUMERIC);

        return $codes;
    }

    /**
     * @return list<int>
     */
    private function canonicalChildAges(): array
    {
        return array_map(static fn (mixed $age): int => (int) $age, array_values($this->childAges));
    }
}
