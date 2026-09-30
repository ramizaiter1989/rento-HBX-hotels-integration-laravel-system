<?php

declare(strict_types=1);

namespace App\Services\HBX;

use Illuminate\Support\Facades\Validator;

class ClientReferenceGenerator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * HBX clientReference must be 1–20 characters.
     * RENTO-{YYMMDD}-{RANDOM7} is exactly 20.
     */
    public function generate(): string
    {
        $reference = 'RENTO-'.now()->format('ymd').'-'.$this->random(7);

        return $this->validate($reference);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'client_reference' => ['required', 'string', 'min:1', 'max:20', 'regex:/^RENTO-\d{6}-[A-Z0-9]{7}$/'],
        ];
    }

    public function validate(string $reference): string
    {
        Validator::make(
            ['client_reference' => $reference],
            $this->rules()
        )->validate();

        return $reference;
    }

    private function random(int $length): string
    {
        $characters = strlen(self::ALPHABET) - 1;
        $value = '';

        for ($index = 0; $index < $length; $index++) {
            $value .= self::ALPHABET[random_int(0, $characters)];
        }

        return $value;
    }
}
