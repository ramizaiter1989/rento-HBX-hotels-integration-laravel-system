<?php

namespace Tests\Unit;

use App\Services\HBX\HbxSignatureGenerator;
use App\Support\DecimalString;
use App\Support\JsonDecimals;
use App\Support\PayloadSanitizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HbxFoundationTest extends TestCase
{
    #[Test]
    public function signature_is_sha256_of_key_secret_and_seconds(): void
    {
        $signature = (new HbxSignatureGenerator)->generate('test-api-key', 'test-secret', 1700000000);

        $this->assertSame('5daa69896964d53bf783a29cc9671338124ff3b2ba8be1d6988adc21c3126c22', $signature);
    }

    #[Test]
    public function a_new_timestamp_changes_the_signature(): void
    {
        $generator = new HbxSignatureGenerator;

        $this->assertNotSame(
            $generator->generate('test-api-key', 'test-secret', 1700000000),
            $generator->generate('test-api-key', 'test-secret', 1700000001)
        );
    }

    #[Test]
    public function money_keeps_decimal_strings_and_rejects_floats(): void
    {
        $this->assertSame('121.18', DecimalString::from('121.18'));
        $this->assertSame('4.40', DecimalString::from('4.40'));
        $this->assertSame('118.13', DecimalString::from('118.13'));
        $this->expectException(InvalidArgumentException::class);
        DecimalString::from(121.18);
    }

    #[Test]
    public function json_decoder_preserves_decimals_and_timezone_text(): void
    {
        $decoded = JsonDecimals::decode('{"net":121.18,"from":"2026-10-08T23:59:00+02:00","allotment":41}');

        $this->assertSame('121.18', $decoded['net']);
        $this->assertSame('2026-10-08T23:59:00+02:00', $decoded['from']);
        $this->assertSame(41, $decoded['allotment']);
    }

    #[Test]
    public function sanitizer_removes_secrets_and_auth_fields(): void
    {
        $clean = (new PayloadSanitizer)->sanitize([
            'Api-key' => 'test-api-key',
            'X-Signature' => 'abc',
            'secret' => 'test-secret',
            'note' => 'contains test-secret inside',
        ]);

        $this->assertSame('[REDACTED]', $clean['Api-key']);
        $this->assertSame('[REDACTED]', $clean['X-Signature']);
        $this->assertSame('[REDACTED]', $clean['secret']);
        $this->assertStringNotContainsString('test-secret', $clean['note']);
    }
}
