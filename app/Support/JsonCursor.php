<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Walks a JSON document by byte offset without decoding it.
 * Structural characters are ASCII, so this stays aligned for UTF-8 text.
 */
final class JsonCursor
{
    private int $length;

    private int $offset = 0;

    public function __construct(private readonly string $json)
    {
        $this->length = strlen($json);
    }

    public function seek(int $offset): void
    {
        $this->offset = max(0, $offset);
    }

    public function position(): int
    {
        return $this->offset;
    }

    public function peek(): ?string
    {
        $this->skipWhitespace();

        return $this->offset < $this->length ? $this->json[$this->offset] : null;
    }

    public function skipValue(): void
    {
        $char = $this->peek();

        if ($char === null) {
            return;
        }

        if ($char === '"') {
            $this->skipString();

            return;
        }

        if ($char === '{' || $char === '[') {
            $this->skipContainer();

            return;
        }

        $this->skipLiteral();
    }

    public function readScalar(): mixed
    {
        $char = $this->peek();

        if ($char === null) {
            return null;
        }

        if ($char === '"') {
            return $this->readString();
        }

        if ($char === '{' || $char === '[') {
            $this->skipValue();

            return null;
        }

        $start = $this->offset;
        $this->skipLiteral();
        $token = substr($this->json, $start, $this->offset - $start);

        return match ($token) {
            'true' => true,
            'false' => false,
            'null', '' => null,
            default => $token,
        };
    }

    /**
     * The callback must consume the value that follows each key.
     *
     * @param  callable(string): void  $callback
     */
    public function eachKey(callable $callback): void
    {
        if ($this->peek() !== '{') {
            $this->skipValue();

            return;
        }

        $this->offset++;

        while ($this->offset < $this->length) {
            $char = $this->peek();

            if ($char === null || $char === '}') {
                if ($char === '}') {
                    $this->offset++;
                }

                return;
            }

            if ($char === ',') {
                $this->offset++;

                continue;
            }

            $key = $this->readString();
            if ($this->peek() === ':') {
                $this->offset++;
            }

            $callback($key);
        }
    }

    /**
     * The callback must consume each array element.
     *
     * @param  callable(): (bool|void)  $callback
     */
    public function eachElement(callable $callback): void
    {
        if ($this->peek() !== '[') {
            $this->skipValue();

            return;
        }

        $this->offset++;

        while ($this->offset < $this->length) {
            $char = $this->peek();

            if ($char === null || $char === ']') {
                if ($char === ']') {
                    $this->offset++;
                }

                return;
            }

            if ($char === ',') {
                $this->offset++;

                continue;
            }

            if ($callback() === false) {
                $this->finishContainer();

                return;
            }
        }
    }

    /**
     * Consume the rest of the container currently open around the cursor.
     */
    private function finishContainer(): void
    {
        $depth = 1;
        $inString = false;

        while ($this->offset < $this->length) {
            $char = $this->json[$this->offset];

            if ($inString) {
                if ($char === '\\') {
                    $this->offset += 2;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                $this->offset++;

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $this->offset++;

                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;
                $this->offset++;

                continue;
            }

            if ($char === '}' || $char === ']') {
                $depth--;
                $this->offset++;

                if ($depth === 0) {
                    return;
                }

                continue;
            }

            $this->offset++;
        }
    }

    private function readString(): string
    {
        if ($this->peek() !== '"') {
            $this->skipValue();

            return '';
        }

        $start = $this->offset;
        $this->skipString();
        $decoded = json_decode(substr($this->json, $start, $this->offset - $start), true);

        return is_string($decoded) ? $decoded : '';
    }

    private function skipString(): void
    {
        $this->offset++;

        while ($this->offset < $this->length) {
            $char = $this->json[$this->offset];

            if ($char === '\\') {
                $this->offset += 2;

                continue;
            }

            $this->offset++;

            if ($char === '"') {
                return;
            }
        }
    }

    private function skipContainer(): void
    {
        $depth = 0;
        $inString = false;

        while ($this->offset < $this->length) {
            $char = $this->json[$this->offset];

            if ($inString) {
                if ($char === '\\') {
                    $this->offset += 2;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                $this->offset++;

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $this->offset++;

                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;
                $this->offset++;

                continue;
            }

            if ($char === '}' || $char === ']') {
                $depth--;
                $this->offset++;

                if ($depth === 0) {
                    return;
                }

                continue;
            }

            $this->offset++;
        }
    }

    private function skipLiteral(): void
    {
        while ($this->offset < $this->length) {
            $char = $this->json[$this->offset];

            if ($char === ',' || $char === '}' || $char === ']' || ctype_space($char)) {
                return;
            }

            $this->offset++;
        }
    }

    private function skipWhitespace(): void
    {
        while ($this->offset < $this->length && ctype_space($this->json[$this->offset])) {
            $this->offset++;
        }
    }
}
