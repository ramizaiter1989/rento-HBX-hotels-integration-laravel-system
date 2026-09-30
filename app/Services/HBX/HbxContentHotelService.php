<?php

declare(strict_types=1);

namespace App\Services\HBX;

use App\DTOs\HBX\HbxResult;
use App\Exceptions\HBX\HbxValidationException;

final class HbxContentHotelService
{
    public function __construct(private readonly HbxClient $client) {}

    public function getHotel(int $hotelCode, string $language = 'ENG'): HbxResult
    {
        if ($hotelCode < 1) {
            throw new HbxValidationException(
                'Hotel code must be a positive HBX hotel code.',
                'INVALID_DATA',
                null,
                [],
                'content_hotel_detail'
            );
        }

        $language = strtoupper(trim($language));

        if ($language === '') {
            $language = 'ENG';
        }

        $path = str_replace(
            '{hotelCode}',
            (string) $hotelCode,
            (string) config('hbx.endpoints.content_hotel_detail')
        );

        return $this->client->get(
            $path,
            [
                'language' => $language,
                'useSecondaryLanguage' => 'false',
            ],
            'content_hotel_detail'
        );
    }
}
