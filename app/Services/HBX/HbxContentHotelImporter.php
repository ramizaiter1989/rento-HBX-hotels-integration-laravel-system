<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\DTOs\HBX\ContentImportResult;
use App\DTOs\HBX\HbxResult;
use App\Exceptions\HBX\HbxValidationException;
use App\Models\ContentHotel;
use App\Models\ContentHotelImage;
use App\Models\ContentHotelInterestPoint;
use App\Models\ContentHotelInterestPointTranslation;
use App\Models\ContentHotelRoom;
use App\Models\ContentHotelRoomTranslation;
use App\Models\ContentRoomFacility;
use App\Models\ContentRoomStay;
use App\Models\ContentRoomStayFacility;
use App\Support\ContentHotelHasher;
use Illuminate\Support\Facades\DB;

final class HbxContentHotelImporter
{
    public function __construct(private readonly ContentHotelHasher $hasher) {}

    public function import(HbxResult $result, string $language, ?int $expectedHotelCode = null, string $origin = 'details'): ContentImportResult
    {
        $language = $this->language($language);
        $origin = $this->origin($origin);
        $hotel = $this->hotelPayload($result);
        $code = $this->hotelCode($hotel);

        if ($expectedHotelCode !== null && $expectedHotelCode !== $code) {
            throw new HbxValidationException(
                'Content hotel code does not match the requested hotel.',
                'INVALID_DATA',
                null,
                [],
                'content_hotel_detail'
            );
        }

        $hash = $this->hasher->hash($result->rawBody);

        return DB::transaction(function () use ($result, $hotel, $language, $code, $hash, $origin): ContentImportResult {
            $record = ContentHotel::query()->where('hbx_hotel_code', $code)->lockForUpdate()->first();
            $snapshot = null;

            if ($record !== null) {
                $snapshot = $record->snapshots()->where('language', $language)->lockForUpdate()->first();

                if ($snapshot !== null && hash_equals($snapshot->content_hash, $hash)) {
                    $snapshot->forceFill(['content_synced_at' => now()])->save();

                    return $this->makeResult(
                        ContentImportResult::UNCHANGED,
                        $record,
                        $language,
                        $hash,
                        (int) $snapshot->payload_bytes
                    );
                }

                // The Hotels list is a lossy shape of Hotel Details. Replacing a details
                // snapshot with it drops fields the list omits and flips the hash forever.
                if ($origin === 'list' && $snapshot !== null && $snapshot->content_origin === 'details') {
                    return $this->makeResult(
                        ContentImportResult::UNCHANGED,
                        $record,
                        $language,
                        (string) $snapshot->content_hash,
                        (int) $snapshot->payload_bytes,
                        ['hotel-details-retained']
                    );
                }
            }

            if ($language !== 'ENG') {
                if ($record === null) {
                    throw new HbxValidationException(
                        'Import ENG content before importing another language. Non-ENG content cannot create shared hotel structure.',
                        'CONTENT_ENG_REQUIRED',
                        null,
                        [],
                        'content_hotel_detail'
                    );
                }

                return $this->importLanguage($record, $hotel, $result, $language, $hash, $snapshot !== null, $origin);
            }

            return $this->importEnglish($record, $hotel, $result, $language, $hash, $snapshot !== null, $origin);
        });
    }

    /**
     * @param  array<string, mixed>  $hotel
     */
    private function importEnglish(?ContentHotel $record, array $hotel, HbxResult $result, string $language, string $hash, bool $existed, string $origin): ContentImportResult
    {
        $record ??= new ContentHotel;
        $record->fill($this->hotelAttributes($hotel, $result->data['version'] ?? null));
        $record->hbx_hotel_code = $this->hotelCode($hotel);
        $record->save();

        $record->translations()->updateOrCreate(
            ['language' => $language],
            $this->translationAttributes($hotel)
        );

        $otherRooms = $this->otherRoomTranslations($record);
        $otherPoints = $this->otherInterestPointTranslations($record);
        $this->deleteSharedChildren($record);

        $rooms = $this->insertRooms($record, $this->listOf($hotel['rooms'] ?? null), $language);
        $unmatched = $this->applyWildcards($this->listOf($hotel['wildcards'] ?? null), $rooms, $language);
        $this->restoreRoomTranslations($rooms, $otherRooms);
        $this->insertImages($record, $this->listOf($hotel['images'] ?? null), $rooms);
        $this->insertPhones($record, $this->listOf($hotel['phones'] ?? null));
        $this->insertBoards($record, $this->listOf($hotel['boards'] ?? null));
        $this->insertSegments($record, $this->listOf($hotel['segments'] ?? null));
        $this->insertHotelFacilities($record, $this->listOf($hotel['facilities'] ?? null));
        $this->insertTerminals($record, $this->listOf($hotel['terminals'] ?? null));
        $points = $this->insertInterestPoints($record, $this->listOf($hotel['interestPoints'] ?? null), $language);
        $this->restoreInterestPointTranslations($points, $otherPoints);
        $this->storeSnapshot($record, $result, $language, $hash, $origin);

        return $this->makeResult(
            $existed ? ContentImportResult::UPDATED : ContentImportResult::IMPORTED,
            $record,
            $language,
            $hash,
            strlen($result->rawBody),
            [],
            $unmatched
        );
    }

    /**
     * @param  array<string, mixed>  $hotel
     */
    private function importLanguage(ContentHotel $record, array $hotel, HbxResult $result, string $language, string $hash, bool $existed, string $origin): ContentImportResult
    {
        $conflicts = ContentHotelStructure::differences($hotel, $this->version($result->data['version'] ?? null), $record);
        $record->translations()->updateOrCreate(
            ['language' => $language],
            $this->translationAttributes($hotel)
        );

        $rooms = $record->rooms()->get()->keyBy('room_code');
        foreach ($this->listOf($hotel['rooms'] ?? null) as $room) {
            if (! is_array($room)) {
                continue;
            }

            $code = $this->nullableCode($room['roomCode'] ?? null);

            if ($code === null || ! isset($rooms[$code])) {
                if ($code !== null) {
                    $conflicts[] = 'room:'.$code;
                }

                continue;
            }

            $translation = $rooms[$code]->translations()->firstOrNew(['language' => $language]);
            $translation->description = $this->rawText($room['description'] ?? null);
            $translation->save();
        }

        $unmatched = $this->applyWildcards($this->listOf($hotel['wildcards'] ?? null), $rooms->all(), $language);
        $points = $record->interestPoints()->get()->keyBy(
            fn (ContentHotelInterestPoint $point): string => $point->facility_code.':'.$point->facility_group_code.':'.$point->sort_order
        );

        foreach ($this->listOf($hotel['interestPoints'] ?? null) as $point) {
            if (! is_array($point)) {
                continue;
            }

            $key = ($this->optionalInt($point, 'facilityCode') ?? '').':'
                .($this->optionalInt($point, 'facilityGroupCode') ?? '').':'
                .($this->optionalInt($point, 'order') ?? '');
            $stored = $points->get($key);

            if ($stored === null) {
                $conflicts[] = 'interest_point:'.$key;

                continue;
            }

            $stored->translations()->updateOrCreate(
                ['language' => $language],
                ['poi_name' => $this->rawText($point['poiName'] ?? null)]
            );
        }

        $this->storeSnapshot($record, $result, $language, $hash, $origin);

        return $this->makeResult(
            $existed ? ContentImportResult::UPDATED : ContentImportResult::IMPORTED,
            $record,
            $language,
            $hash,
            strlen($result->rawBody),
            $conflicts,
            $unmatched
        );
    }

    /**
     * @param  array<string, mixed>  $hotel
     * @return array<string, mixed>
     */
    private function hotelAttributes(array $hotel, mixed $version): array
    {
        $country = $this->arrayNode($hotel['country'] ?? null);
        $state = $this->arrayNode($hotel['state'] ?? null);
        $destination = $this->arrayNode($hotel['destination'] ?? null);
        $zone = $this->arrayNode($hotel['zone'] ?? null);
        $coordinates = $this->arrayNode($hotel['coordinates'] ?? null);
        $category = $this->arrayNode($hotel['category'] ?? null);
        $categoryGroup = $this->arrayNode($hotel['categoryGroup'] ?? null);
        $chain = $this->arrayNode($hotel['chain'] ?? null);
        $accommodation = $this->arrayNode($hotel['accommodationType'] ?? null);
        $address = $this->arrayNode($hotel['address'] ?? null);

        return [
            'country_code' => $this->nullableCode($country['code'] ?? null),
            'country_iso_code' => $this->nullableCode($country['isoCode'] ?? null),
            'state_code' => $this->nullableCode($state['code'] ?? null),
            'destination_code' => $this->nullableCode($destination['code'] ?? null),
            'destination_country_code' => $this->nullableCode($destination['countryCode'] ?? null),
            'zone_code' => $this->optionalInt($zone, 'zoneCode'),
            'latitude' => $this->optionalDecimal($coordinates, 'latitude'),
            'longitude' => $this->optionalDecimal($coordinates, 'longitude'),
            'category_code' => $this->nullableCode($category['code'] ?? null),
            'category_group_code' => $this->nullableCode($categoryGroup['code'] ?? null),
            'chain_code' => $this->nullableCode($chain['code'] ?? null),
            'accommodation_type_code' => $this->nullableCode($accommodation['code'] ?? null),
            'postal_code' => $this->nullableCode($hotel['postalCode'] ?? null),
            'address_number' => $this->nullableCode($address['number'] ?? null),
            'email' => $this->nullableCode($hotel['email'] ?? null),
            'license' => $this->rawText($hotel['license'] ?? null),
            'giata_code' => $this->optionalInt($hotel, 'giataCode'),
            'web' => $this->nullableCode($hotel['web'] ?? null),
            'supplier_last_update' => $this->optionalDate($hotel, 'lastUpdate'),
            's2c' => $this->rawText($hotel['S2C'] ?? null),
            'ranking' => $this->optionalInt($hotel, 'ranking'),
            'source_version' => $this->version($version),
        ];
    }

    /**
     * @param  array<string, mixed>  $hotel
     * @return array<string, mixed>
     */
    private function translationAttributes(array $hotel): array
    {
        $address = $this->arrayNode($hotel['address'] ?? null);

        return [
            'name' => $this->requireText($hotel['name'] ?? null, 'name'),
            'description' => $this->rawText($hotel['description'] ?? null),
            'address_line' => $this->rawText($address['content'] ?? null),
            'address_street' => $this->rawText($address['street'] ?? null),
            'city' => $this->rawText($hotel['city'] ?? null),
        ];
    }

    /**
     * @return array<string, array<string, array{description: ?string, commercial_description: ?string}>>
     */
    private function otherRoomTranslations(ContentHotel $hotel): array
    {
        if (! $hotel->exists) {
            return [];
        }

        $kept = [];

        foreach ($hotel->rooms()->with('translations')->get() as $room) {
            foreach ($room->translations as $translation) {
                if ($translation->language === 'ENG') {
                    continue;
                }

                $kept[$room->room_code][$translation->language] = [
                    'description' => $translation->description,
                    'commercial_description' => $translation->commercial_description,
                ];
            }
        }

        return $kept;
    }

    /**
     * @return array<string, array<string, array{poi_name: ?string}>>
     */
    private function otherInterestPointTranslations(ContentHotel $hotel): array
    {
        if (! $hotel->exists) {
            return [];
        }

        $kept = [];

        foreach ($hotel->interestPoints()->with('translations')->get() as $point) {
            $key = $point->facility_code.':'.$point->facility_group_code.':'.$point->sort_order;

            foreach ($point->translations as $translation) {
                if ($translation->language === 'ENG') {
                    continue;
                }

                $kept[$key][$translation->language] = [
                    'poi_name' => $translation->poi_name,
                ];
            }
        }

        return $kept;
    }

    private function deleteSharedChildren(ContentHotel $hotel): void
    {
        $roomIds = $hotel->rooms()->pluck('id');

        if ($roomIds->isNotEmpty()) {
            $stayIds = ContentRoomStay::query()->whereIn('content_hotel_room_id', $roomIds)->pluck('id');

            if ($stayIds->isNotEmpty()) {
                ContentRoomStayFacility::query()->whereIn('content_room_stay_id', $stayIds)->delete();
            }

            ContentRoomStay::query()->whereIn('content_hotel_room_id', $roomIds)->delete();
            ContentRoomFacility::query()->whereIn('content_hotel_room_id', $roomIds)->delete();
            ContentHotelRoomTranslation::query()->whereIn('content_hotel_room_id', $roomIds)->delete();
        }

        $pointIds = $hotel->interestPoints()->pluck('id');

        if ($pointIds->isNotEmpty()) {
            ContentHotelInterestPointTranslation::query()->whereIn('content_hotel_interest_point_id', $pointIds)->delete();
        }

        ContentHotelImage::query()->where('content_hotel_id', $hotel->id)->delete();
        $hotel->rooms()->delete();
        $hotel->interestPoints()->delete();
        $hotel->phones()->delete();
        $hotel->boards()->delete();
        $hotel->segments()->delete();
        $hotel->facilities()->delete();
        $hotel->terminals()->delete();
    }

    /**
     * @param  list<mixed>  $rooms
     * @return array<string, ContentHotelRoom>
     */
    private function insertRooms(ContentHotel $hotel, array $rooms, string $language): array
    {
        $map = [];

        foreach ($rooms as $room) {
            if (! is_array($room)) {
                throw new HbxValidationException('Content room must be an object.', 'INVALID_DATA', null, [], 'content_hotel_detail');
            }

            $type = $this->arrayNode($room['type'] ?? null);
            $characteristic = $this->arrayNode($room['characteristic'] ?? null);
            $model = $hotel->rooms()->create([
                'room_code' => $this->requireString($room['roomCode'] ?? null, 'roomCode'),
                'type_code' => $this->requireString($type['code'] ?? null, 'type.code'),
                'characteristic_code' => $this->requireString($characteristic['code'] ?? null, 'characteristic.code'),
                'is_parent_room' => (bool) ($room['isParentRoom'] ?? false),
                'min_pax' => $this->requireIntValue($room['minPax'] ?? null, 'minPax'),
                'max_pax' => $this->requireIntValue($room['maxPax'] ?? null, 'maxPax'),
                'min_adults' => $this->requireIntValue($room['minAdults'] ?? null, 'minAdults'),
                'max_adults' => $this->requireIntValue($room['maxAdults'] ?? null, 'maxAdults'),
                'max_children' => $this->requireIntValue($room['maxChildren'] ?? null, 'maxChildren'),
                'pms_room_code' => $this->nullableCode($room['PMSRoomCode'] ?? null),
            ]);
            $model->translations()->create([
                'language' => $language,
                'description' => $this->rawText($room['description'] ?? null),
                'commercial_description' => null,
            ]);

            foreach ($this->listOf($room['roomFacilities'] ?? null) as $facility) {
                $model->facilities()->create($this->facilityAttributes($facility));
            }

            foreach ($this->listOf($room['roomStays'] ?? null) as $stay) {
                if (! is_array($stay)) {
                    throw new HbxValidationException('Content room stay must be an object.', 'INVALID_DATA', null, [], 'content_hotel_detail');
                }

                $stayModel = $model->stays()->create([
                    'stay_type' => $this->requireString($stay['stayType'] ?? null, 'stayType'),
                    'stay_order' => $this->requireString($stay['order'] ?? null, 'roomStay.order'),
                ]);

                foreach ($this->listOf($stay['roomStayFacilities'] ?? null) as $facility) {
                    $stayModel->facilities()->create($this->facilityAttributes($facility));
                }
            }

            $map[$model->room_code] = $model;
        }

        return $map;
    }

    /**
     * @param  list<mixed>  $wildcards
     * @param  array<string, ContentHotelRoom>  $rooms
     * @return list<string>
     */
    private function applyWildcards(array $wildcards, array $rooms, string $language): array
    {
        $unmatched = [];

        foreach ($wildcards as $wildcard) {
            if (! is_array($wildcard)) {
                continue;
            }

            $roomCode = $this->nullableCode($wildcard['roomType'] ?? null);

            if ($roomCode === null) {
                continue;
            }

            $room = $rooms[$roomCode] ?? null;

            if (! $room instanceof ContentHotelRoom) {
                $unmatched[] = $roomCode;

                continue;
            }

            $translation = $room->translations()->firstOrNew(['language' => $language]);
            $translation->commercial_description = $this->rawText($wildcard['hotelRoomDescription'] ?? null);
            if (! $translation->exists) {
                $translation->description = $translation->description ?? null;
            }
            $translation->save();
        }

        return $unmatched;
    }

    /**
     * @param  array<string, ContentHotelRoom>  $rooms
     * @param  array<string, array<string, array{description: ?string, commercial_description: ?string}>>  $remembered
     */
    private function restoreRoomTranslations(array $rooms, array $remembered): void
    {
        foreach ($remembered as $roomCode => $languages) {
            $room = $rooms[$roomCode] ?? null;

            if ($room === null) {
                continue;
            }

            foreach ($languages as $language => $values) {
                $room->translations()->updateOrCreate(['language' => $language], $values);
            }
        }
    }

    /**
     * Images keep the supplier array order. source_position is that zero-based index.
     *
     * @param  list<mixed>  $images
     * @param  array<string, ContentHotelRoom>  $rooms
     */
    private function insertImages(ContentHotel $hotel, array $images, array $rooms): void
    {
        foreach ($images as $sourcePosition => $image) {
            if (! is_array($image)) {
                throw new HbxValidationException('Content image must be an object.', 'INVALID_DATA', null, [], 'content_hotel_detail');
            }

            $type = $this->arrayNode($image['type'] ?? null);
            $roomCode = $this->nullableCode($image['roomCode'] ?? null);
            $room = $roomCode !== null ? ($rooms[$roomCode] ?? null) : null;

            $hotel->images()->create([
                'content_hotel_room_id' => $room?->id,
                'image_type_code' => $this->requireString($type['code'] ?? null, 'image.type.code'),
                'path' => $this->requireString($image['path'] ?? null, 'image.path'),
                'room_code' => $roomCode,
                'room_type_code' => $this->nullableCode($image['roomType'] ?? null),
                'characteristic_code' => $this->nullableCode($image['characteristicCode'] ?? null),
                'supplier_order' => $this->requireIntValue($image['order'] ?? null, 'image.order'),
                'visual_order' => $this->requireIntValue($image['visualOrder'] ?? null, 'image.visualOrder'),
                'source_position' => $sourcePosition,
            ]);
        }
    }

    /**
     * @param  list<mixed>  $phones
     */
    private function insertPhones(ContentHotel $hotel, array $phones): void
    {
        foreach ($phones as $phone) {
            if (! is_array($phone)) {
                throw new HbxValidationException('Content phone must be an object.', 'INVALID_DATA', null, [], 'content_hotel_detail');
            }

            $hotel->phones()->create([
                'phone_number' => $this->requireString($phone['phoneNumber'] ?? null, 'phoneNumber'),
                'phone_type' => $this->requireString($phone['phoneType'] ?? null, 'phoneType'),
            ]);
        }
    }

    /**
     * @param  list<mixed>  $boards
     */
    private function insertBoards(ContentHotel $hotel, array $boards): void
    {
        foreach ($boards as $board) {
            if (! is_array($board)) {
                throw new HbxValidationException('Content board must be an object.', 'INVALID_DATA', null, [], 'content_hotel_detail');
            }

            $hotel->boards()->create([
                'board_code' => $this->requireString($board['code'] ?? null, 'board.code'),
            ]);
        }
    }

    /**
     * @param  list<mixed>  $segments
     */
    private function insertSegments(ContentHotel $hotel, array $segments): void
    {
        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                throw new HbxValidationException('Content segment must be an object.', 'INVALID_DATA', null, [], 'content_hotel_detail');
            }

            $hotel->segments()->create([
                'segment_code' => $this->requireIntValue($segment['code'] ?? null, 'segment.code'),
            ]);
        }
    }

    /**
     * @param  list<mixed>  $facilities
     */
    private function insertHotelFacilities(ContentHotel $hotel, array $facilities): void
    {
        foreach ($facilities as $facility) {
            $hotel->facilities()->create($this->facilityAttributes($facility));
        }
    }

    /**
     * @param  list<mixed>  $terminals
     */
    private function insertTerminals(ContentHotel $hotel, array $terminals): void
    {
        foreach ($terminals as $terminal) {
            if (! is_array($terminal)) {
                throw new HbxValidationException('Content terminal must be an object.', 'INVALID_DATA', null, [], 'content_hotel_detail');
            }

            $hotel->terminals()->create([
                'terminal_code' => $this->requireString($terminal['terminalCode'] ?? null, 'terminalCode'),
                'terminal_type' => $this->nullableCode($terminal['terminalType'] ?? null),
                'distance' => $this->optionalInt($terminal, 'distance'),
            ]);
        }
    }

    /**
     * @param  list<mixed>  $points
     * @return array<string, ContentHotelInterestPoint>
     */
    private function insertInterestPoints(ContentHotel $hotel, array $points, string $language): array
    {
        $map = [];

        foreach ($points as $point) {
            if (! is_array($point)) {
                throw new HbxValidationException('Content interest point must be an object.', 'INVALID_DATA', null, [], 'content_hotel_detail');
            }

            $model = $hotel->interestPoints()->create([
                'facility_code' => $this->requireIntValue($point['facilityCode'] ?? null, 'interestPoint.facilityCode'),
                'facility_group_code' => $this->requireIntValue($point['facilityGroupCode'] ?? null, 'interestPoint.facilityGroupCode'),
                'sort_order' => $this->requireIntValue($point['order'] ?? null, 'interestPoint.order'),
                'distance' => $this->optionalInt($point, 'distance'),
            ]);
            $model->translations()->create([
                'language' => $language,
                'poi_name' => $this->rawText($point['poiName'] ?? null),
            ]);
            $map[$model->facility_code.':'.$model->facility_group_code.':'.$model->sort_order] = $model;
        }

        return $map;
    }

    /**
     * @param  array<string, ContentHotelInterestPoint>  $points
     * @param  array<string, array<string, array{poi_name: ?string}>>  $remembered
     */
    private function restoreInterestPointTranslations(array $points, array $remembered): void
    {
        foreach ($remembered as $key => $languages) {
            $point = $points[$key] ?? null;

            if ($point === null) {
                continue;
            }

            foreach ($languages as $language => $values) {
                $point->translations()->updateOrCreate(['language' => $language], $values);
            }
        }
    }

    private function storeSnapshot(ContentHotel $hotel, HbxResult $result, string $language, string $hash, string $origin): void
    {
        $hotel->snapshots()->updateOrCreate(
            ['language' => $language],
            [
                'raw_payload' => $result->rawBody,
                'content_hash' => $hash,
                'payload_bytes' => strlen($result->rawBody),
                'content_origin' => $origin,
                'content_synced_at' => now(),
            ]
        );
    }

    private function origin(string $origin): string
    {
        if ($origin !== 'details' && $origin !== 'list') {
            throw new HbxValidationException(
                'Content snapshot origin must be details or list.',
                'INVALID_DATA',
                null,
                [],
                'content_hotel_detail'
            );
        }

        return $origin;
    }

    /**
     * @return array<string, mixed>
     */
    private function facilityAttributes(mixed $facility): array
    {
        if (! is_array($facility)) {
            throw new HbxValidationException('Content facility must be an object.', 'INVALID_DATA', null, [], 'content_hotel_detail');
        }

        return [
            'facility_code' => $this->requireIntValue($facility['facilityCode'] ?? null, 'facilityCode'),
            'facility_group_code' => $this->requireIntValue($facility['facilityGroupCode'] ?? null, 'facilityGroupCode'),
            'sort_order' => $this->optionalInt($facility, 'order'),
            'number_value' => $this->optionalInt($facility, 'number'),
            'ind_logic' => $this->optionalBool($facility, 'indLogic'),
            'ind_fee' => $this->optionalBool($facility, 'indFee'),
            'ind_yes_or_no' => $this->optionalBool($facility, 'indYesOrNo'),
            'voucher' => $this->optionalBool($facility, 'voucher'),
            'distance' => $this->optionalInt($facility, 'distance'),
            'amount' => $this->optionalDecimal($facility, 'amount'),
            'currency' => $this->nullableCode($facility['currency'] ?? null),
            'application_type' => $this->nullableCode($facility['applicationType'] ?? null),
            'time_from' => $this->optionalTime($facility, 'timeFrom'),
            'time_to' => $this->optionalTime($facility, 'timeTo'),
            'date_to' => $this->optionalDate($facility, 'dateTo'),
        ];
    }

    /**
     * @param  list<string>  $conflicts
     * @param  list<string>  $unmatched
     */
    private function makeResult(string $status, ContentHotel $hotel, string $language, string $hash, int $bytes, array $conflicts = [], array $unmatched = []): ContentImportResult
    {
        return new ContentImportResult(
            status: $status,
            hotelCode: (int) $hotel->hbx_hotel_code,
            language: $language,
            rooms: $hotel->rooms()->count(),
            images: $hotel->images()->count(),
            facilities: $hotel->facilities()->count(),
            snapshotBytes: $bytes,
            hash: $hash,
            conflicts: array_values($conflicts),
            unmatchedWildcards: array_values($unmatched),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function hotelPayload(HbxResult $result): array
    {
        if ($result->httpStatus < 200 || $result->httpStatus >= 300) {
            throw new HbxValidationException(
                'Content import requires a successful Hotel Details response.',
                'INVALID_DATA',
                $result->httpStatus,
                [],
                'content_hotel_detail'
            );
        }

        $hotel = $result->data['hotel'] ?? null;

        if (! is_array($hotel)) {
            throw new HbxValidationException(
                'Content response is missing the hotel object.',
                'INVALID_DATA',
                null,
                [],
                'content_hotel_detail'
            );
        }

        return $hotel;
    }

    /**
     * @param  array<string, mixed>  $hotel
     */
    private function hotelCode(array $hotel): int
    {
        $code = $hotel['code'] ?? null;

        if (is_string($code) && preg_match('/^\d+$/', $code) === 1) {
            $code = (int) $code;
        }

        if (! is_int($code) || $code < 1) {
            throw new HbxValidationException('Content hotel code is missing.', 'INVALID_DATA', null, [], 'content_hotel_detail');
        }

        return $code;
    }

    private function language(string $language): string
    {
        $language = strtoupper(trim($language));

        if ($language === '' || strlen($language) > 3) {
            throw new HbxValidationException(
                'Content language must be a 1 to 3 letter code.',
                'INVALID_DATA',
                null,
                [],
                'content_hotel_detail'
            );
        }

        return $language;
    }

    private function version(mixed $version): ?string
    {
        if (! is_string($version) || $version === '' || strlen($version) > 8) {
            return null;
        }

        return $version;
    }

    private function requireText(mixed $value, string $label): string
    {
        $text = $this->rawText($value);

        if ($text === null || $text === '') {
            throw new HbxValidationException(
                'Content hotel is missing '.$label.'.',
                'INVALID_DATA',
                null,
                [],
                'content_hotel_detail'
            );
        }

        return $text;
    }

    private function rawText(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        if (is_array($value) && is_string($value['content'] ?? null)) {
            return $value['content'] === '' ? null : $value['content'];
        }

        return null;
    }

    private function requireString(mixed $value, string $label): string
    {
        $code = $this->nullableCode($value);

        if ($code === null) {
            throw new HbxValidationException(
                'Content field '.$label.' is missing.',
                'INVALID_DATA',
                null,
                [],
                'content_hotel_detail'
            );
        }

        return $code;
    }

    private function nullableCode(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function requireIntValue(mixed $value, string $label): int
    {
        $integer = $this->asInt($value);

        if ($integer === null) {
            throw new HbxValidationException(
                'Content field '.$label.' must be an integer.',
                'INVALID_DATA',
                null,
                [],
                'content_hotel_detail'
            );
        }

        return $integer;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function optionalInt(array $row, string $key): ?int
    {
        if (! array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }

        return $this->asInt($row[$key]);
    }

    private function asInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function optionalBool(array $row, string $key): ?bool
    {
        if (! array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }

        return (bool) $row[$key];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function optionalDecimal(array $row, string $key): ?string
    {
        if (! array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }

        $value = $row[$key];

        if (is_int($value)) {
            return ContentHotelHasher::canonicalNumber((string) $value);
        }

        if (is_string($value) && preg_match('/^[+-]?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?$/', trim($value)) === 1) {
            return ContentHotelHasher::canonicalNumber(trim($value));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function optionalDate(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        return substr($value, 0, 10);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function optionalTime(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || preg_match('/^\d{2}:\d{2}:\d{2}/', $value) !== 1) {
            return null;
        }

        return substr($value, 0, 8);
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayNode(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<mixed>
     */
    private function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
