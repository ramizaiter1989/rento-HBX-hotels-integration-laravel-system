<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\Models\ContentHotel;
use App\Models\ContentHotelFacility;
use App\Models\ContentHotelImage;
use App\Models\ContentHotelInterestPoint;
use App\Models\ContentHotelRoom;
use App\Models\ContentRoomFacility;
use App\Models\ContentRoomStay;
use App\Models\ContentRoomStayFacility;
use App\Support\ContentHotelHasher;
use DateTimeInterface;

final class ContentHotelStructure
{
    /**
     * @param  array<string, mixed>  $hotel
     * @return list<string>
     */
    public static function differences(array $hotel, ?string $version, ContentHotel $record): array
    {
        $incoming = self::fromPayload($hotel, $version);
        $stored = self::fromHotel($record);
        $keys = array_values(array_unique([...array_keys($incoming), ...array_keys($stored)]));
        sort($keys);
        $differences = [];

        foreach ($keys as $key) {
            $left = json_encode($incoming[$key] ?? null, JSON_THROW_ON_ERROR);
            $right = json_encode($stored[$key] ?? null, JSON_THROW_ON_ERROR);

            if ($left !== $right) {
                $differences[] = $key;
            }
        }

        return $differences;
    }

    /**
     * @param  array<string, mixed>  $hotel
     * @return array<string, mixed>
     */
    public static function fromPayload(array $hotel, ?string $version): array
    {
        $country = self::arrayNode($hotel['country'] ?? null);
        $state = self::arrayNode($hotel['state'] ?? null);
        $destination = self::arrayNode($hotel['destination'] ?? null);
        $zone = self::arrayNode($hotel['zone'] ?? null);
        $coordinates = self::arrayNode($hotel['coordinates'] ?? null);
        $category = self::arrayNode($hotel['category'] ?? null);
        $categoryGroup = self::arrayNode($hotel['categoryGroup'] ?? null);
        $chain = self::arrayNode($hotel['chain'] ?? null);
        $accommodation = self::arrayNode($hotel['accommodationType'] ?? null);
        $address = self::arrayNode($hotel['address'] ?? null);

        return [
            'source_version' => $version,
            'country_code' => self::stringOrNull($country['code'] ?? null),
            'country_iso_code' => self::stringOrNull($country['isoCode'] ?? null),
            'state_code' => self::stringOrNull($state['code'] ?? null),
            'destination_code' => self::stringOrNull($destination['code'] ?? null),
            'destination_country_code' => self::stringOrNull($destination['countryCode'] ?? null),
            'zone_code' => self::intOrNull($zone['zoneCode'] ?? null),
            'latitude' => self::decimalOrNull($coordinates['latitude'] ?? null),
            'longitude' => self::decimalOrNull($coordinates['longitude'] ?? null),
            'category_code' => self::stringOrNull($category['code'] ?? null),
            'category_group_code' => self::stringOrNull($categoryGroup['code'] ?? null),
            'chain_code' => self::stringOrNull($chain['code'] ?? null),
            'accommodation_type_code' => self::stringOrNull($accommodation['code'] ?? null),
            'postal_code' => self::stringOrNull($hotel['postalCode'] ?? null),
            'address_number' => self::stringOrNull($address['number'] ?? null),
            'email' => self::stringOrNull($hotel['email'] ?? null),
            'license' => self::stringOrNull($hotel['license'] ?? null),
            'giata_code' => self::intOrNull($hotel['giataCode'] ?? null),
            'web' => self::stringOrNull($hotel['web'] ?? null),
            'supplier_last_update' => self::dateOrNull($hotel['lastUpdate'] ?? null),
            's2c' => self::stringOrNull($hotel['S2C'] ?? null),
            'ranking' => self::intOrNull($hotel['ranking'] ?? null),
            'phones' => self::sortRows(array_map(
                fn (mixed $phone): array => [
                    'phone_number' => self::stringOrNull(self::arrayNode($phone)['phoneNumber'] ?? null),
                    'phone_type' => self::stringOrNull(self::arrayNode($phone)['phoneType'] ?? null),
                ],
                self::listNode($hotel['phones'] ?? null)
            ), ['phone_type', 'phone_number']),
            'boards' => self::sortRows(array_map(
                fn (mixed $board): array => ['board_code' => self::stringOrNull(self::arrayNode($board)['code'] ?? null)],
                self::listNode($hotel['boards'] ?? null)
            ), ['board_code']),
            'segments' => self::sortRows(array_map(
                fn (mixed $segment): array => ['segment_code' => self::intOrNull(self::arrayNode($segment)['code'] ?? null)],
                self::listNode($hotel['segments'] ?? null)
            ), ['segment_code']),
            'rooms' => self::sortRows(array_map(
                fn (mixed $room): array => self::roomFromPayload(self::arrayNode($room)),
                self::listNode($hotel['rooms'] ?? null)
            ), ['room_code']),
            'facilities' => self::sortRows(array_map(
                fn (mixed $facility): array => self::facilityFromPayload(self::arrayNode($facility)),
                self::listNode($hotel['facilities'] ?? null)
            ), ['facility_code', 'facility_group_code']),
            'images' => self::sortRows(array_map(
                fn (mixed $image): array => self::imageFromPayload(self::arrayNode($image)),
                self::listNode($hotel['images'] ?? null)
            ), ['path', 'visual_order', 'room_code']),
            'terminals' => self::sortRows(array_map(
                fn (mixed $terminal): array => self::terminalFromPayload(self::arrayNode($terminal)),
                self::listNode($hotel['terminals'] ?? null)
            ), ['terminal_code', 'terminal_type']),
            'interest_points' => self::sortRows(array_map(
                fn (mixed $point): array => self::interestPointFromPayload(self::arrayNode($point)),
                self::listNode($hotel['interestPoints'] ?? null)
            ), ['facility_code', 'facility_group_code', 'sort_order']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromHotel(ContentHotel $hotel): array
    {
        $hotel->load([
            'phones',
            'boards',
            'segments',
            'facilities',
            'images',
            'terminals',
            'interestPoints',
            'rooms.facilities',
            'rooms.stays.facilities',
        ]);

        return [
            'source_version' => $hotel->source_version,
            'country_code' => self::trimOrNull($hotel->country_code),
            'country_iso_code' => self::trimOrNull($hotel->country_iso_code),
            'state_code' => self::trimOrNull($hotel->state_code),
            'destination_code' => self::trimOrNull($hotel->destination_code),
            'destination_country_code' => self::trimOrNull($hotel->destination_country_code),
            'zone_code' => $hotel->zone_code,
            'latitude' => self::decimalOrNull($hotel->latitude),
            'longitude' => self::decimalOrNull($hotel->longitude),
            'category_code' => $hotel->category_code,
            'category_group_code' => $hotel->category_group_code,
            'chain_code' => $hotel->chain_code,
            'accommodation_type_code' => $hotel->accommodation_type_code,
            'postal_code' => $hotel->postal_code,
            'address_number' => $hotel->address_number,
            'email' => $hotel->email,
            'license' => $hotel->license,
            'giata_code' => $hotel->giata_code,
            'web' => $hotel->web,
            'supplier_last_update' => $hotel->supplier_last_update?->format('Y-m-d'),
            's2c' => $hotel->s2c,
            'ranking' => $hotel->ranking,
            'phones' => self::sortRows($hotel->phones->map(fn ($phone): array => [
                'phone_number' => $phone->phone_number,
                'phone_type' => $phone->phone_type,
            ])->all(), ['phone_type', 'phone_number']),
            'boards' => self::sortRows($hotel->boards->map(fn ($board): array => [
                'board_code' => $board->board_code,
            ])->all(), ['board_code']),
            'segments' => self::sortRows($hotel->segments->map(fn ($segment): array => [
                'segment_code' => $segment->segment_code,
            ])->all(), ['segment_code']),
            'rooms' => self::sortRows($hotel->rooms->map(fn (ContentHotelRoom $room): array => [
                'room_code' => $room->room_code,
                'type_code' => $room->type_code,
                'characteristic_code' => $room->characteristic_code,
                'is_parent_room' => $room->is_parent_room,
                'min_pax' => $room->min_pax,
                'max_pax' => $room->max_pax,
                'min_adults' => $room->min_adults,
                'max_adults' => $room->max_adults,
                'max_children' => $room->max_children,
                'pms_room_code' => $room->pms_room_code,
                'facilities' => self::sortRows($room->facilities->map(fn (ContentRoomFacility $facility): array => self::facilityFromModel($facility))->all(), ['facility_code', 'facility_group_code']),
                'stays' => self::sortRows($room->stays->map(fn (ContentRoomStay $stay): array => [
                    'stay_type' => $stay->stay_type,
                    'stay_order' => $stay->stay_order,
                    'facilities' => self::sortRows($stay->facilities->map(fn (ContentRoomStayFacility $facility): array => self::facilityFromModel($facility))->all(), ['facility_code', 'facility_group_code']),
                ])->all(), ['stay_type', 'stay_order']),
            ])->all(), ['room_code']),
            'facilities' => self::sortRows($hotel->facilities->map(fn (ContentHotelFacility $facility): array => self::facilityFromModel($facility))->all(), ['facility_code', 'facility_group_code']),
            'images' => self::sortRows($hotel->images->map(fn (ContentHotelImage $image): array => [
                'image_type_code' => $image->image_type_code,
                'path' => $image->path,
                'room_code' => $image->room_code,
                'room_type_code' => $image->room_type_code,
                'characteristic_code' => $image->characteristic_code,
                'supplier_order' => $image->supplier_order,
                'visual_order' => $image->visual_order,
            ])->all(), ['path', 'visual_order', 'room_code']),
            'terminals' => self::sortRows($hotel->terminals->map(fn ($terminal): array => [
                'terminal_code' => self::trimOrNull($terminal->terminal_code),
                'terminal_type' => self::trimOrNull($terminal->terminal_type),
                'distance' => $terminal->distance,
            ])->all(), ['terminal_code', 'terminal_type']),
            'interest_points' => self::sortRows($hotel->interestPoints->map(fn (ContentHotelInterestPoint $point): array => [
                'facility_code' => $point->facility_code,
                'facility_group_code' => $point->facility_group_code,
                'sort_order' => $point->sort_order,
                'distance' => $point->distance,
            ])->all(), ['facility_code', 'facility_group_code', 'sort_order']),
        ];
    }

    /**
     * @param  array<string, mixed>  $room
     * @return array<string, mixed>
     */
    private static function roomFromPayload(array $room): array
    {
        $type = self::arrayNode($room['type'] ?? null);
        $characteristic = self::arrayNode($room['characteristic'] ?? null);
        $pms = $room['PMSRoomCode'] ?? null;

        return [
            'room_code' => self::stringOrNull($room['roomCode'] ?? null),
            'type_code' => self::stringOrNull($type['code'] ?? null),
            'characteristic_code' => self::stringOrNull($characteristic['code'] ?? null),
            'is_parent_room' => (bool) ($room['isParentRoom'] ?? false),
            'min_pax' => self::intOrNull($room['minPax'] ?? null),
            'max_pax' => self::intOrNull($room['maxPax'] ?? null),
            'min_adults' => self::intOrNull($room['minAdults'] ?? null),
            'max_adults' => self::intOrNull($room['maxAdults'] ?? null),
            'max_children' => self::intOrNull($room['maxChildren'] ?? null),
            'pms_room_code' => self::stringOrNull(is_string($pms) || is_int($pms) ? $pms : null),
            'facilities' => self::sortRows(array_map(
                fn (mixed $facility): array => self::facilityFromPayload(self::arrayNode($facility)),
                self::listNode($room['roomFacilities'] ?? null)
            ), ['facility_code', 'facility_group_code']),
            'stays' => self::sortRows(array_map(
                fn (mixed $stay): array => self::stayFromPayload(self::arrayNode($stay)),
                self::listNode($room['roomStays'] ?? null)
            ), ['stay_type', 'stay_order']),
        ];
    }

    /**
     * @param  array<string, mixed>  $stay
     * @return array<string, mixed>
     */
    private static function stayFromPayload(array $stay): array
    {
        return [
            'stay_type' => self::stringOrNull($stay['stayType'] ?? null),
            'stay_order' => self::stringOrNull($stay['order'] ?? null),
            'facilities' => self::sortRows(array_map(
                fn (mixed $facility): array => self::facilityFromPayload(self::arrayNode($facility)),
                self::listNode($stay['roomStayFacilities'] ?? null)
            ), ['facility_code', 'facility_group_code']),
        ];
    }

    /**
     * @param  array<string, mixed>  $facility
     * @return array<string, mixed>
     */
    private static function facilityFromPayload(array $facility): array
    {
        return [
            'facility_code' => self::intOrNull($facility['facilityCode'] ?? null),
            'facility_group_code' => self::intOrNull($facility['facilityGroupCode'] ?? null),
            'sort_order' => self::intOrNull($facility['order'] ?? null),
            'number_value' => self::intOrNull($facility['number'] ?? null),
            'ind_logic' => self::boolOrNull($facility['indLogic'] ?? null, array_key_exists('indLogic', $facility)),
            'ind_fee' => self::boolOrNull($facility['indFee'] ?? null, array_key_exists('indFee', $facility)),
            'ind_yes_or_no' => self::boolOrNull($facility['indYesOrNo'] ?? null, array_key_exists('indYesOrNo', $facility)),
            'voucher' => self::boolOrNull($facility['voucher'] ?? null, array_key_exists('voucher', $facility)),
            'distance' => self::intOrNull($facility['distance'] ?? null),
            'amount' => self::decimalOrNull($facility['amount'] ?? null),
            'currency' => self::stringOrNull($facility['currency'] ?? null),
            'application_type' => self::stringOrNull($facility['applicationType'] ?? null),
            'time_from' => self::timeOrNull($facility['timeFrom'] ?? null),
            'time_to' => self::timeOrNull($facility['timeTo'] ?? null),
            'date_to' => self::dateOrNull($facility['dateTo'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function facilityFromModel(ContentHotelFacility|ContentRoomFacility|ContentRoomStayFacility $facility): array
    {
        return [
            'facility_code' => $facility->facility_code,
            'facility_group_code' => $facility->facility_group_code,
            'sort_order' => $facility->sort_order,
            'number_value' => $facility->number_value,
            'ind_logic' => $facility->ind_logic,
            'ind_fee' => $facility->ind_fee,
            'ind_yes_or_no' => $facility->ind_yes_or_no,
            'voucher' => $facility->voucher,
            'distance' => $facility->distance,
            'amount' => self::decimalOrNull($facility->amount),
            'currency' => self::trimOrNull($facility->currency),
            'application_type' => $facility->application_type,
            'time_from' => self::timeOrNull($facility->time_from),
            'time_to' => self::timeOrNull($facility->time_to),
            'date_to' => self::dateOrNull($facility->date_to),
        ];
    }

    /**
     * @param  array<string, mixed>  $image
     * @return array<string, mixed>
     */
    private static function imageFromPayload(array $image): array
    {
        $type = self::arrayNode($image['type'] ?? null);

        return [
            'image_type_code' => self::stringOrNull($type['code'] ?? null),
            'path' => self::stringOrNull($image['path'] ?? null),
            'room_code' => self::stringOrNull($image['roomCode'] ?? null),
            'room_type_code' => self::stringOrNull($image['roomType'] ?? null),
            'characteristic_code' => self::stringOrNull($image['characteristicCode'] ?? null),
            'supplier_order' => self::intOrNull($image['order'] ?? null),
            'visual_order' => self::intOrNull($image['visualOrder'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $terminal
     * @return array<string, mixed>
     */
    private static function terminalFromPayload(array $terminal): array
    {
        return [
            'terminal_code' => self::stringOrNull($terminal['terminalCode'] ?? null),
            'terminal_type' => self::stringOrNull($terminal['terminalType'] ?? null),
            'distance' => self::intOrNull($terminal['distance'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $point
     * @return array<string, mixed>
     */
    private static function interestPointFromPayload(array $point): array
    {
        return [
            'facility_code' => self::intOrNull($point['facilityCode'] ?? null),
            'facility_group_code' => self::intOrNull($point['facilityGroupCode'] ?? null),
            'sort_order' => self::intOrNull($point['order'] ?? null),
            'distance' => self::intOrNull($point['distance'] ?? null),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private static function sortRows(array $rows, array $keys): array
    {
        usort($rows, static function (array $left, array $right) use ($keys): int {
            foreach ($keys as $key) {
                $comparison = ($left[$key] ?? null) <=> ($right[$key] ?? null);

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        return array_values($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayNode(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<mixed>
     */
    private static function listNode(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private static function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function boolOrNull(mixed $value, bool $present): ?bool
    {
        if (! $present || $value === null) {
            return null;
        }

        return (bool) $value;
    }

    private static function decimalOrNull(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value) && preg_match('/^[+-]?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?$/', trim($value)) === 1) {
            return ContentHotelHasher::canonicalNumber(trim($value));
        }

        return null;
    }

    private static function dateOrNull(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value)) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : null;
    }

    private static function timeOrNull(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s');
        }

        if (! is_string($value)) {
            return null;
        }

        return preg_match('/^\d{2}:\d{2}:\d{2}/', $value) === 1 ? substr($value, 0, 8) : null;
    }
}
