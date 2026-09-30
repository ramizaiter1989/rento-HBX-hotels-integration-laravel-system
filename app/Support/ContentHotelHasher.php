<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\HBX\HbxValidationException;

/**
 * SHA-256 of a canonical encoding of the Content API `hotel` object.
 * Root version and auditData never enter the hash.
 */
final class ContentHotelHasher
{
    public function hash(string $rawBody): string
    {
        return hash('sha256', $this->canonicalHotel($rawBody));
    }

    public function canonicalHotel(string $rawBody): string
    {
        $root = $this->parse($rawBody);

        if (! $root instanceof CanonicalJsonObject || ! array_key_exists('hotel', $root->properties)) {
            throw new HbxValidationException(
                'Content response is missing the hotel object.',
                'INVALID_DATA',
                null,
                [],
                'content_hotel_detail'
            );
        }

        $hotel = $root->properties['hotel'];

        if (! $hotel instanceof CanonicalJsonObject) {
            throw new HbxValidationException(
                'Content response hotel value must be a JSON object.',
                'INVALID_DATA',
                null,
                [],
                'content_hotel_detail'
            );
        }

        return $this->encode($hotel);
    }

    public static function canonicalNumber(string $literal): string
    {
        if (preg_match('/^([+-])?(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $literal, $matches) !== 1) {
            throw new HbxValidationException(
                'Content JSON contains an invalid number.',
                'INVALID_DATA',
                null,
                [],
                'content_hotel_detail'
            );
        }

        $negative = ($matches[1] ?? '') === '-';
        $digits = $matches[2].($matches[3] ?? '');
        $scale = strlen($matches[3] ?? '') - (isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : 0);
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return '0';
        }

        if ($scale <= 0) {
            $rendered = $digits.str_repeat('0', -$scale);
        } elseif (strlen($digits) <= $scale) {
            $rendered = '0.'.str_repeat('0', $scale - strlen($digits)).$digits;
            $rendered = rtrim($rendered, '0');
            $rendered = str_ends_with($rendered, '.') ? substr($rendered, 0, -1) : $rendered;
        } else {
            $split = strlen($digits) - $scale;
            $fraction = rtrim(substr($digits, $split), '0');
            $rendered = $fraction === '' ? substr($digits, 0, $split) : substr($digits, 0, $split).'.'.$fraction;
        }

        if ($negative && $rendered !== '0') {
            return '-'.$rendered;
        }

        return $rendered;
    }

    private function parse(string $json): mixed
    {
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
        $parser = new CanonicalJsonParser($json);

        return $parser->parse();
    }

    private function encode(mixed $value): string
    {
        if ($value instanceof CanonicalJsonObject) {
            $properties = $value->properties;
            uksort($properties, static fn (string $left, string $right): int => strcmp($left, $right));
            $parts = [];

            foreach ($properties as $key => $item) {
                $parts[] = $this->encodeString((string) $key).':'.$this->encode($item);
            }

            return '{'.implode(',', $parts).'}';
        }

        if ($value instanceof CanonicalJsonNumber) {
            return $value->canonical;
        }

        if (is_array($value)) {
            return '['.implode(',', array_map($this->encode(...), $value)).']';
        }

        if (is_string($value)) {
            return $this->encodeString($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        throw new HbxValidationException(
            'Content JSON contains a value that cannot be canonicalized.',
            'INVALID_DATA',
            null,
            [],
            'content_hotel_detail'
        );
    }

    private function encodeString(string $value): string
    {
        $encoded = '"';
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];
            $ordinal = ord($character);

            if ($character === '"') {
                $encoded .= '\\"';
            } elseif ($character === '\\') {
                $encoded .= '\\\\';
            } elseif ($ordinal < 0x20) {
                $encoded .= match ($character) {
                    "\b" => '\\b',
                    "\f" => '\\f',
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    default => sprintf('\\u%04x', $ordinal),
                };
            } else {
                $encoded .= $character;
            }
        }

        return $encoded.'"';
    }
}

final class CanonicalJsonObject
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(public array $properties) {}
}

final class CanonicalJsonNumber
{
    public function __construct(public string $canonical) {}
}

final class CanonicalJsonParser
{
    private int $index = 0;

    private readonly int $length;

    public function __construct(private readonly string $json)
    {
        $this->length = strlen($json);
    }

    public function parse(): mixed
    {
        $value = $this->parseValue();
        $this->skipWhitespace();

        if ($this->index !== $this->length) {
            $this->fail('Content JSON has trailing data.');
        }

        return $value;
    }

    private function parseValue(): mixed
    {
        $this->skipWhitespace();

        if ($this->index >= $this->length) {
            $this->fail('Content JSON ended early.');
        }

        $character = $this->json[$this->index];

        return match ($character) {
            '{' => $this->parseObject(),
            '[' => $this->parseArray(),
            '"' => $this->parseString(),
            't' => $this->parseLiteral('true', true),
            'f' => $this->parseLiteral('false', false),
            'n' => $this->parseLiteral('null', null),
            '-', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' => $this->parseNumber(),
            default => $this->fail('Content JSON contains an unexpected character.'),
        };
    }

    private function parseObject(): CanonicalJsonObject
    {
        $this->index++;
        $properties = [];
        $this->skipWhitespace();

        if ($this->peek() === '}') {
            $this->index++;

            return new CanonicalJsonObject($properties);
        }

        while (true) {
            $this->skipWhitespace();

            if ($this->peek() !== '"') {
                $this->fail('Content JSON object key must be a string.');
            }

            $key = $this->parseString();
            $this->skipWhitespace();

            if ($this->peek() !== ':') {
                $this->fail('Content JSON object is missing a colon.');
            }

            $this->index++;
            $properties[$key] = $this->parseValue();
            $this->skipWhitespace();
            $next = $this->peek();

            if ($next === '}') {
                $this->index++;

                return new CanonicalJsonObject($properties);
            }

            if ($next !== ',') {
                $this->fail('Content JSON object is missing a comma.');
            }

            $this->index++;
        }
    }

    /**
     * @return list<mixed>
     */
    private function parseArray(): array
    {
        $this->index++;
        $items = [];
        $this->skipWhitespace();

        if ($this->peek() === ']') {
            $this->index++;

            return $items;
        }

        while (true) {
            $items[] = $this->parseValue();
            $this->skipWhitespace();
            $next = $this->peek();

            if ($next === ']') {
                $this->index++;

                return $items;
            }

            if ($next !== ',') {
                $this->fail('Content JSON array is missing a comma.');
            }

            $this->index++;
        }
    }

    private function parseString(): string
    {
        $this->index++;
        $value = '';

        while ($this->index < $this->length) {
            $character = $this->json[$this->index];

            if ($character === '"') {
                $this->index++;

                return $value;
            }

            if ($character === '\\') {
                $this->index++;
                $escape = $this->peek();
                $this->index++;
                $value .= match ($escape) {
                    '"', '\\', '/' => $escape,
                    'b' => "\b",
                    'f' => "\f",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'u' => $this->parseUnicodeEscape(),
                    default => $this->fail('Content JSON contains an invalid escape.'),
                };

                continue;
            }

            if (ord($character) < 0x20) {
                $this->fail('Content JSON contains a raw control character.');
            }

            $value .= $character;
            $this->index++;
        }

        $this->fail('Content JSON string ended early.');
    }

    private function parseUnicodeEscape(): string
    {
        $code = $this->readUnicodeHex();

        if ($code >= 0xD800 && $code <= 0xDBFF) {
            if (substr($this->json, $this->index, 2) !== '\\u') {
                $this->fail('Content JSON has a dangling unicode surrogate.');
            }

            $this->index += 2;
            $low = $this->readUnicodeHex();
            $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
        }

        $character = mb_chr($code, 'UTF-8');

        if ($character === false) {
            $this->fail('Content JSON has an invalid unicode escape.');
        }

        return $character;
    }

    private function readUnicodeHex(): int
    {
        $hex = substr($this->json, $this->index, 4);

        if (preg_match('/^[0-9a-fA-F]{4}$/', $hex) !== 1) {
            $this->fail('Content JSON has an invalid unicode escape.');
        }

        $this->index += 4;

        return hexdec($hex);
    }

    private function parseNumber(): CanonicalJsonNumber
    {
        $start = $this->index;

        if ($this->peek() === '-') {
            $this->index++;
        }

        if ($this->peek() === '0') {
            $this->index++;
        } elseif (ctype_digit($this->peek())) {
            while ($this->index < $this->length && ctype_digit($this->json[$this->index])) {
                $this->index++;
            }
        } else {
            $this->fail('Content JSON contains an invalid number.');
        }

        if ($this->peek() === '.') {
            $this->index++;

            if (! ctype_digit($this->peek())) {
                $this->fail('Content JSON contains an invalid number.');
            }

            while ($this->index < $this->length && ctype_digit($this->json[$this->index])) {
                $this->index++;
            }
        }

        if ($this->peek() === 'e' || $this->peek() === 'E') {
            $this->index++;

            if ($this->peek() === '+' || $this->peek() === '-') {
                $this->index++;
            }

            if (! ctype_digit($this->peek())) {
                $this->fail('Content JSON contains an invalid number.');
            }

            while ($this->index < $this->length && ctype_digit($this->json[$this->index])) {
                $this->index++;
            }
        }

        return new CanonicalJsonNumber(ContentHotelHasher::canonicalNumber(substr($this->json, $start, $this->index - $start)));
    }

    private function parseLiteral(string $literal, mixed $value): mixed
    {
        if (substr($this->json, $this->index, strlen($literal)) !== $literal) {
            $this->fail('Content JSON contains an invalid literal.');
        }

        $this->index += strlen($literal);

        return $value;
    }

    private function skipWhitespace(): void
    {
        while ($this->index < $this->length && ctype_space($this->json[$this->index])) {
            $this->index++;
        }
    }

    private function peek(): string
    {
        return $this->index < $this->length ? $this->json[$this->index] : '';
    }

    private function fail(string $message): never
    {
        throw new HbxValidationException($message, 'INVALID_DATA', null, [], 'content_hotel_detail');
    }
}
