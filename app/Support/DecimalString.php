<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class DecimalString
{
    public static function from(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException('Money values must not be PHP floats.');
        }

        if (is_int($value)) {
            return BigDecimal::of($value)->__toString();
        }

        $string = trim((string) $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $string)) {
            return null;
        }

        return BigDecimal::of($string)->__toString();
    }

    public static function display(?string $amount, ?string $currency = null): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $decimal = BigDecimal::of($amount);
        $scaled = $decimal->getScale() > 2
            ? $decimal->toScale(2, RoundingMode::HALF_UP)->__toString()
            : $decimal->toScale(2)->__toString();

        return $currency ? $scaled.' '.$currency : $scaled;
    }
}
