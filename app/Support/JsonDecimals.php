<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

final class JsonDecimals
{
    /**
     * Decode JSON while keeping decimal numbers as strings.
     * Integers stay integers. Quoted supplier timestamps stay untouched.
     */
    public static function decode(string $json): array
    {
        $trimmed = trim($json);

        if ($trimmed === '' || $trimmed === 'null') {
            return [];
        }

        $preserved = preg_replace(
            '/(?<=[:\[,])\s*(-?\d+\.\d+(?:[eE][+\-]?\d+)?)(?=\s*[,\]\}])/',
            '"$1"',
            $trimmed
        );

        try {
            $decoded = json_decode($preserved ?? $trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JsonException('HBX response was not valid JSON.', 0, $exception);
        }

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
