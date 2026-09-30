<?php

declare(strict_types=1);

namespace App\Support;

final class PositiveConfigInt
{
    public static function from(mixed $value, int $default): int
    {
        if ($default < 1) {
            $default = 1;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return $default;
        }

        $number = (int) $value;

        return $number > 0 ? $number : $default;
    }
}
