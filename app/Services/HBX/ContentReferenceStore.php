<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\Models\ContentHotel;
use Illuminate\Support\Facades\DB;

/**
 * Normalized HBX reference catalogs. Supplier codes stay the identity.
 * Descriptions are stored once per language.
 */
final class ContentReferenceStore
{
    /**
     * @return array<string, array{table: string, translation: string, identity: list<string>}>
     */
    public function definitions(): array
    {
        return [
            'facility-groups' => $this->define('content_ref_facility_groups', 'content_ref_facility_group_translations', ['code']),
            'facilities' => $this->define('content_ref_facilities', 'content_ref_facility_translations', ['code', 'facility_group_code']),
            'room-types' => $this->define('content_ref_room_types', 'content_ref_room_type_translations', ['code']),
            'room-characteristics' => $this->define('content_ref_room_characteristics', 'content_ref_room_characteristic_translations', ['code']),
            'rooms' => $this->define('content_ref_rooms', 'content_ref_room_translations', ['code']),
            'categories' => $this->define('content_ref_categories', 'content_ref_category_translations', ['code']),
            'category-groups' => $this->define('content_ref_category_groups', 'content_ref_category_group_translations', ['code']),
            'chains' => $this->define('content_ref_chains', 'content_ref_chain_translations', ['code']),
            'accommodations' => $this->define('content_ref_accommodation_types', 'content_ref_accommodation_type_translations', ['code']),
            'boards' => $this->define('content_ref_boards', 'content_ref_board_translations', ['code']),
            'segments' => $this->define('content_ref_segments', 'content_ref_segment_translations', ['code']),
            'image-types' => $this->define('content_ref_image_types', 'content_ref_image_type_translations', ['code']),
            'countries' => $this->define('content_ref_countries', 'content_ref_country_translations', ['code']),
            'destinations' => $this->define('content_ref_destinations', 'content_ref_destination_translations', ['code']),
            'zones' => $this->define('content_ref_zones', 'content_ref_zone_translations', ['destination_code', 'zone_code']),
        ];
    }

    /**
     * @param  array<string, int|string|null>  $identity
     * @param  array<string, int|string|null>  $extra
     */
    public function upsert(string $type, array $identity, string $language, ?string $description, array $extra = []): string
    {
        $definition = $this->definitions()[$type];
        $language = strtoupper($language);
        $description = $this->clean($description);

        return DB::transaction(function () use ($definition, $identity, $language, $description, $extra): string {
            $existing = DB::table($definition['table'])->where($this->only($identity, $definition['identity']))->first();
            $status = 'unchanged';

            if ($existing === null) {
                $id = DB::table($definition['table'])->insertGetId($identity + $extra + [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $status = 'imported';
            } else {
                $id = (int) $existing->id;
                $updates = [];

                foreach ($extra as $column => $value) {
                    if (! $this->same($existing->{$column} ?? null, $value)) {
                        $updates[$column] = $value;
                    }
                }

                if ($updates !== []) {
                    $updates['updated_at'] = now();
                    DB::table($definition['table'])->where('id', $id)->update($updates);
                    $status = 'updated';
                }
            }

            if ($description === null) {
                return $status;
            }

            $translation = DB::table($definition['translation'])
                ->where('record_id', $id)
                ->where('language', $language)
                ->first();

            if ($translation === null) {
                DB::table($definition['translation'])->insert([
                    'record_id' => $id,
                    'language' => $language,
                    'description' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $status === 'unchanged' ? 'updated' : $status;
            }

            if (! $this->same($translation->description, $description)) {
                DB::table($definition['translation'])->where('id', $translation->id)->update([
                    'description' => $description,
                    'updated_at' => now(),
                ]);

                return 'updated';
            }

            return $status;
        });
    }

    /**
     * @param  list<int|string>  $codes
     * @return array<string, string>
     */
    public function descriptionsForCodes(string $type, array $codes, string $language): array
    {
        $codes = array_values(array_unique(array_filter(
            $codes,
            fn (mixed $code): bool => $code !== null && $code !== '' && $code !== 0,
        )));

        if ($codes === []) {
            return [];
        }

        $definition = $this->definitions()[$type];
        $rows = DB::table($definition['table'].' as records')
            ->join($definition['translation'].' as translations', 'translations.record_id', '=', 'records.id')
            ->where('translations.language', strtoupper($language))
            ->whereIn('records.code', $codes)
            ->get(['records.code', 'translations.description']);

        $labels = [];

        foreach ($rows as $row) {
            if (is_string($row->description) && $row->description !== '') {
                $labels[(string) $row->code] = $row->description;
            }
        }

        return $labels;
    }

    /**
     * @return array<string, mixed>
     */
    public function labelsForHotel(ContentHotel $hotel, string $language): array
    {
        $language = strtoupper($language);
        $facilityPairs = [];

        foreach ($hotel->facilities as $facility) {
            $facilityPairs[] = [(int) $facility->facility_code, (int) $facility->facility_group_code];
        }

        foreach ($hotel->rooms as $room) {
            foreach ($room->facilities as $facility) {
                $facilityPairs[] = [(int) $facility->facility_code, (int) $facility->facility_group_code];
            }

            foreach ($room->stays as $stay) {
                foreach ($stay->facilities as $facility) {
                    $facilityPairs[] = [(int) $facility->facility_code, (int) $facility->facility_group_code];
                }
            }
        }

        return [
            'countries' => $this->descriptionsForCodes('countries', array_filter([$hotel->country_code]), $language),
            'destinations' => $this->descriptionsForCodes('destinations', array_filter([$hotel->destination_code]), $language),
            'zones' => $this->zoneLabel($hotel->destination_code, $hotel->zone_code, $language),
            'categories' => $this->descriptionsForCodes('categories', array_filter([$hotel->category_code]), $language),
            'category_groups' => $this->descriptionsForCodes('category-groups', array_filter([$hotel->category_group_code]), $language),
            'chains' => $this->descriptionsForCodes('chains', array_filter([$hotel->chain_code]), $language),
            'accommodations' => $this->descriptionsForCodes('accommodations', array_filter([$hotel->accommodation_type_code]), $language),
            'boards' => $this->descriptionsForCodes('boards', $hotel->boards->pluck('board_code')->all(), $language),
            'segments' => $this->descriptionsForCodes('segments', $hotel->segments->pluck('segment_code')->all(), $language),
            'image_types' => $this->descriptionsForCodes('image-types', $hotel->images->pluck('image_type_code')->all(), $language),
            'room_types' => $this->descriptionsForCodes('room-types', $hotel->rooms->pluck('type_code')->all(), $language),
            'room_characteristics' => $this->descriptionsForCodes('room-characteristics', $hotel->rooms->pluck('characteristic_code')->all(), $language),
            'rooms' => $this->descriptionsForCodes('rooms', $hotel->rooms->pluck('room_code')->all(), $language),
            'facilities' => $this->facilityLabels($facilityPairs, $language),
            'facility_groups' => $this->descriptionsForCodes('facility-groups', array_map(fn (array $pair): int => $pair[1], $facilityPairs), $language),
        ];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $pairs
     * @return array<string, string>
     */
    public function facilityLabels(array $pairs, string $language): array
    {
        $pairs = array_values(array_filter($pairs, fn (array $pair): bool => $pair[0] > 0));

        if ($pairs === []) {
            return [];
        }

        $codes = array_values(array_unique(array_map(fn (array $pair): int => $pair[0], $pairs)));
        $rows = DB::table('content_ref_facilities as records')
            ->join('content_ref_facility_translations as translations', 'translations.record_id', '=', 'records.id')
            ->where('translations.language', strtoupper($language))
            ->whereIn('records.code', $codes)
            ->get(['records.code', 'records.facility_group_code', 'translations.description']);
        $labels = [];

        foreach ($rows as $row) {
            if (is_string($row->description) && $row->description !== '') {
                $labels[$row->code.':'.$row->facility_group_code] = $row->description;
            }
        }

        return $labels;
    }

    public function zoneLabel(?string $destinationCode, ?int $zoneCode, string $language): ?string
    {
        if ($destinationCode === null || $destinationCode === '' || $zoneCode === null) {
            return null;
        }

        $row = DB::table('content_ref_zones as records')
            ->join('content_ref_zone_translations as translations', 'translations.record_id', '=', 'records.id')
            ->where('records.destination_code', $destinationCode)
            ->where('records.zone_code', $zoneCode)
            ->where('translations.language', strtoupper($language))
            ->value('translations.description');

        return is_string($row) && $row !== '' ? $row : null;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->definitions() as $type => $definition) {
            $counts[$type] = DB::table($definition['table'])->count();
        }

        return $counts;
    }

    /**
     * @param  list<string>  $identity
     * @return array{table: string, translation: string, identity: list<string>}
     */
    private function define(string $table, string $translation, array $identity): array
    {
        return [
            'table' => $table,
            'translation' => $translation,
            'identity' => $identity,
        ];
    }

    /**
     * @param  array<string, int|string|null>  $values
     * @param  list<string>  $keys
     * @return array<string, int|string|null>
     */
    private function only(array $values, array $keys): array
    {
        $identity = [];

        foreach ($keys as $key) {
            $identity[$key] = $values[$key] ?? null;
        }

        return $identity;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 512);
    }

    private function same(mixed $current, mixed $incoming): bool
    {
        if ($current === null || $incoming === null) {
            return $current === null && $incoming === null;
        }

        return (string) $current === (string) $incoming;
    }
}
