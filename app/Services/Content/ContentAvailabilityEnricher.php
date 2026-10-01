<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentHotel;
use App\Models\ContentHotelFacility;
use App\Models\ContentHotelImage;
use App\Models\ContentHotelRoom;
use App\Services\HBX\ContentReferenceStore;
use App\Support\ContentImageUrl;
use Illuminate\Support\Str;

/**
 * Joins stored Content rows onto Availability results.
 * Identity is the supplier hotel code and the exact room code.
 */
final class ContentAvailabilityEnricher
{
    public function __construct(private readonly ContentReferenceStore $references) {}

    /**
     * @param  list<int|string|null>  $hotelCodes
     * @return array<string, array<string, mixed>>
     */
    public function forHotels(array $hotelCodes, string $language = 'ENG'): array
    {
        $codes = $this->codes($hotelCodes);

        if ($codes === []) {
            return [];
        }

        $language = $this->language($language);
        $hotels = ContentHotel::query()
            ->select([
                'id',
                'hbx_hotel_code',
                'country_code',
                'destination_code',
                'zone_code',
                'category_code',
                'chain_code',
                'accommodation_type_code',
                'latitude',
                'longitude',
            ])
            ->whereIn('hbx_hotel_code', $codes)
            ->with([
                'translations' => fn ($query) => $query->select([
                    'id',
                    'content_hotel_id',
                    'language',
                    'name',
                    'city',
                    'description',
                ])->where('language', $language),
                'snapshots' => fn ($query) => $query->select([
                    'id',
                    'content_hotel_id',
                    'language',
                    'content_origin',
                ])->where('language', $language),
            ])
            ->get();

        if ($hotels->isEmpty()) {
            return [];
        }

        $ids = $hotels->pluck('id')->all();
        $images = [];

        foreach ($hotels as $hotel) {
            $image = ContentHotelImage::query()
                ->where('content_hotel_id', $hotel->id)
                ->orderBy('visual_order')
                ->orderBy('source_position')
                ->first(['content_hotel_id', 'path']);

            if ($image !== null) {
                $images[$hotel->id] = $image;
            }
        }
        $facilities = ContentHotelFacility::query()
            ->whereIn('content_hotel_id', $ids)
            ->orderBy('sort_order')
            ->get(['content_hotel_id', 'facility_code', 'facility_group_code']);
        $pairs = [];

        foreach ($facilities as $facility) {
            $pairs[] = [(int) $facility->facility_code, (int) $facility->facility_group_code];
        }

        $facilityLabels = $this->references->facilityLabels($pairs, $language);
        $groupLabels = $this->references->descriptionsForCodes('facility-groups', array_map(fn (array $pair): int => $pair[1], $pairs), $language);
        $categoryLabels = $this->references->descriptionsForCodes('categories', $hotels->pluck('category_code')->filter()->all(), $language);
        $destinationLabels = $this->references->descriptionsForCodes('destinations', $hotels->pluck('destination_code')->filter()->all(), $language);
        $byHotel = [];

        foreach ($facilities as $facility) {
            $byHotel[$facility->content_hotel_id] ??= [];

            if (count($byHotel[$facility->content_hotel_id]) >= 6) {
                continue;
            }

            $key = $facility->facility_code.':'.$facility->facility_group_code;
            $byHotel[$facility->content_hotel_id][] = [
                'code' => (string) $facility->facility_code,
                'group' => (string) $facility->facility_group_code,
                'label' => $facilityLabels[$key] ?? null,
                'group_label' => $groupLabels[(string) $facility->facility_group_code] ?? null,
            ];
        }

        $enriched = [];

        foreach ($hotels as $hotel) {
            $translation = $hotel->translations->first();
            $snapshot = $hotel->snapshots->first();
            $image = $images[$hotel->id] ?? null;
            $enriched[(string) $hotel->hbx_hotel_code] = [
                'available' => true,
                'name' => $translation->name ?? null,
                'city' => $translation->city ?? null,
                'description' => $translation?->description ? Str::limit(trim(strip_tags((string) $translation->description)), 180) : null,
                'category_code' => $hotel->category_code,
                'category_label' => $categoryLabels[(string) $hotel->category_code] ?? null,
                'destination_code' => $hotel->destination_code,
                'destination_label' => $destinationLabels[(string) $hotel->destination_code] ?? null,
                'country_code' => $hotel->country_code,
                'zone_code' => $hotel->zone_code,
                'latitude' => $hotel->latitude,
                'longitude' => $hotel->longitude,
                'chain_code' => $hotel->chain_code,
                'accommodation_type_code' => $hotel->accommodation_type_code,
                'origin' => $snapshot->content_origin ?? null,
                'image' => $image ? ContentImageUrl::thumbnail($image->path) : null,
                'facilities' => $byHotel[$hotel->id] ?? [],
            ];
        }

        return $enriched;
    }

    /**
     * @param  list<array<string, mixed>>  $liveRooms
     * @return array<string, mixed>
     */
    public function roomAudit(int|string $hotelCode, array $liveRooms, string $language = 'ENG'): array
    {
        $language = $this->language($language);
        $hotel = ContentHotel::query()->where('hbx_hotel_code', (int) $hotelCode)->first();
        $liveCodes = [];

        foreach ($liveRooms as $room) {
            $code = isset($room['code']) ? trim((string) $room['code']) : '';

            if ($code !== '') {
                $liveCodes[] = $code;
            }
        }

        $liveCodes = array_values(array_unique($liveCodes));

        if (! $hotel instanceof ContentHotel) {
            return [
                'content_available' => false,
                'hotel' => null,
                'live' => $liveCodes,
                'matched' => [],
                'unmatched_live' => $liveCodes,
                'orphan_content' => [],
                'images_unknown_room' => [],
                'rooms' => [],
            ];
        }

        $contentRooms = ContentHotelRoom::query()
            ->where('content_hotel_id', $hotel->id)
            ->with([
                'translations' => fn ($query) => $query->where('language', $language),
            ])
            ->get();
        $contentCodes = $contentRooms->pluck('room_code')->map(fn (mixed $code): string => (string) $code)->all();
        $matched = array_values(array_intersect($liveCodes, $contentCodes));
        $imageRoomCodes = ContentHotelImage::query()
            ->where('content_hotel_id', $hotel->id)
            ->whereNotNull('room_code')
            ->distinct()
            ->pluck('room_code')
            ->map(fn (mixed $code): string => (string) $code)
            ->all();
        $unknownImages = array_values(array_diff($imageRoomCodes, $contentCodes));
        $roomImages = ContentHotelImage::query()
            ->where('content_hotel_id', $hotel->id)
            ->whereIn('room_code', $matched === [] ? [''] : $matched)
            ->orderBy('visual_order')
            ->orderBy('source_position')
            ->get(['room_code', 'path', 'image_type_code']);
        $imagesByRoom = [];

        foreach ($roomImages as $image) {
            $imagesByRoom[$image->room_code] ??= [];

            if (count($imagesByRoom[$image->room_code]) >= 4) {
                continue;
            }

            $url = ContentImageUrl::thumbnail($image->path);

            if ($url !== null) {
                $imagesByRoom[$image->room_code][] = $url;
            }
        }

        $catalog = $this->references->descriptionsForCodes('rooms', $contentCodes, $language);
        $rooms = [];

        foreach ($contentRooms as $room) {
            $translation = $room->translations->first();
            $rooms[(string) $room->room_code] = [
                'code' => (string) $room->room_code,
                'type_code' => $room->type_code,
                'characteristic_code' => $room->characteristic_code,
                'description' => $translation->description ?? null,
                'commercial_description' => $translation->commercial_description ?? null,
                'catalog_description' => $catalog[(string) $room->room_code] ?? null,
                'images' => $imagesByRoom[(string) $room->room_code] ?? [],
            ];
        }

        return [
            'content_available' => true,
            'hotel' => $this->forHotels([$hotelCode], $language)[(string) (int) $hotelCode] ?? null,
            'live' => $liveCodes,
            'matched' => $matched,
            'unmatched_live' => array_values(array_diff($liveCodes, $contentCodes)),
            'orphan_content' => array_values(array_diff($contentCodes, $liveCodes)),
            'images_unknown_room' => $unknownImages,
            'rooms' => $rooms,
        ];
    }

    /**
     * @param  list<int|string|null>  $hotelCodes
     * @return list<int>
     */
    private function codes(array $hotelCodes): array
    {
        $codes = [];

        foreach ($hotelCodes as $code) {
            if (is_int($code) && $code > 0) {
                $codes[] = $code;
            } elseif (is_string($code) && preg_match('/^\d{1,10}$/', $code) === 1) {
                $codes[] = (int) $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function language(string $language): string
    {
        $language = strtoupper($language);

        return preg_match('/^[A-Z]{3}$/', $language) === 1 ? $language : 'ENG';
    }
}
