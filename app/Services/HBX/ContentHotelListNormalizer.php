<?php

declare(strict_types=1);

namespace App\Services\HBX;

/**
 * Converts one Hotels-list hotel into the nested shape the content importer already stores.
 * The list flattens reference objects into codes and flattens room and image type codes.
 */
final class ContentHotelListNormalizer
{
    /**
     * @param  array<string, mixed>  $hotel
     * @return array<string, mixed>
     */
    public function normalize(array $hotel): array
    {
        $hotel = $this->nestCode($hotel, 'categoryCode', 'category', 'code');
        $hotel = $this->nestCode($hotel, 'categoryGroupCode', 'categoryGroup', 'code');
        $hotel = $this->nestCode($hotel, 'chainCode', 'chain', 'code');
        $hotel = $this->nestCode($hotel, 'countryCode', 'country', 'code');
        $hotel = $this->nestCode($hotel, 'destinationCode', 'destination', 'code');
        $hotel = $this->nestCode($hotel, 'stateCode', 'state', 'code');
        $hotel = $this->nestCode($hotel, 'zoneCode', 'zone', 'zoneCode');
        $hotel = $this->nestCode($hotel, 'accommodationTypeCode', 'accommodationType', 'code');
        $hotel = $this->nestList($hotel, 'boardCodes', 'boards');
        $hotel = $this->nestList($hotel, 'segmentCodes', 'segments');

        if (isset($hotel['rooms']) && is_array($hotel['rooms'])) {
            $rooms = [];

            foreach ($hotel['rooms'] as $room) {
                $rooms[] = is_array($room) ? $this->normalizeRoom($room) : $room;
            }

            $hotel['rooms'] = $rooms;
        }

        if (isset($hotel['images']) && is_array($hotel['images'])) {
            $images = [];

            // Keep every supplier image, including repeated path and visualOrder values.
            // Array order is the supplier order. The importer stores that index as source_position.
            foreach ($hotel['images'] as $image) {
                $images[] = is_array($image) ? $this->normalizeImage($image) : $image;
            }

            $hotel['images'] = $images;
        }

        return $hotel;
    }

    /**
     * @param  array<string, mixed>  $hotel
     * @return array<string, mixed>
     */
    private function nestCode(array $hotel, string $flatKey, string $nestedKey, string $codeKey): array
    {
        if (! array_key_exists($flatKey, $hotel)) {
            return $hotel;
        }

        $nested = $hotel[$nestedKey] ?? null;

        if (! is_array($nested)) {
            $nested = [];
        }

        if (! array_key_exists($codeKey, $nested) || $nested[$codeKey] === null || $nested[$codeKey] === '') {
            $nested[$codeKey] = $hotel[$flatKey];
        }

        $hotel[$nestedKey] = $nested;
        unset($hotel[$flatKey]);

        return $hotel;
    }

    /**
     * @param  array<string, mixed>  $hotel
     * @return array<string, mixed>
     */
    private function nestList(array $hotel, string $flatKey, string $nestedKey): array
    {
        if (! array_key_exists($flatKey, $hotel)) {
            return $hotel;
        }

        if (! isset($hotel[$nestedKey]) && is_array($hotel[$flatKey])) {
            $items = [];

            foreach ($hotel[$flatKey] as $code) {
                $items[] = is_array($code) ? $code : ['code' => $code];
            }

            $hotel[$nestedKey] = $items;
        }

        unset($hotel[$flatKey]);

        return $hotel;
    }

    /**
     * List rooms carry roomType and characteristicCode instead of nested type objects.
     * Wildcards are left unchanged: their roomType is already the full room code.
     *
     * @param  array<string, mixed>  $room
     * @return array<string, mixed>
     */
    private function normalizeRoom(array $room): array
    {
        if (! isset($room['type']) && array_key_exists('roomType', $room)) {
            $room['type'] = ['code' => $room['roomType']];
        }

        unset($room['roomType']);

        if (! isset($room['characteristic']) && array_key_exists('characteristicCode', $room)) {
            $room['characteristic'] = ['code' => $room['characteristicCode']];
        }

        unset($room['characteristicCode']);

        return $room;
    }

    /**
     * @param  array<string, mixed>  $image
     * @return array<string, mixed>
     */
    private function normalizeImage(array $image): array
    {
        if (! isset($image['type']) && array_key_exists('imageTypeCode', $image)) {
            $image['type'] = ['code' => $image['imageTypeCode']];
        }

        unset($image['imageTypeCode']);

        return $image;
    }
}
