<?php

declare(strict_types=1);

namespace App\Support;

final class PayloadSanitizer
{
    private const SENSITIVE_KEYS = [
        'api-key',
        'api_key',
        'apikey',
        'x-signature',
        'x_signature',
        'signature',
        'secret',
        'password',
        'authorization',
        'private_key',
        'client_key',
        'certificate',
        'cert',
    ];

    public function sanitize(mixed $payload): mixed
    {
        $secrets = array_values(array_filter([
            (string) config('hbx.secret'),
            (string) config('hbx.api_key'),
        ], fn (string $value): bool => $value !== ''));

        return $this->walk($payload, $secrets);
    }

    public function redactString(string $value): string
    {
        foreach (array_filter([(string) config('hbx.secret'), (string) config('hbx.api_key')]) as $secret) {
            if ($secret !== '') {
                $value = str_replace($secret, '[REDACTED]', $value);
            }
        }

        return $value;
    }

    /**
     * @param  list<string>  $secrets
     */
    private function walk(mixed $payload, array $secrets): mixed
    {
        if (is_array($payload)) {
            $clean = [];

            foreach ($payload as $key => $value) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $clean[$key] = '[REDACTED]';

                    continue;
                }

                $clean[$key] = $this->walk($value, $secrets);
            }

            return $clean;
        }

        if (! is_string($payload)) {
            return $payload;
        }

        foreach ($secrets as $secret) {
            $payload = str_replace($secret, '[REDACTED]', $payload);
        }

        return $payload;
    }

    private function isSensitiveKey(string $key): bool
    {
        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }
}
