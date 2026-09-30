<?php

namespace Tests\Unit;

use App\Support\ContentHotelHasher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentHotelHasherTest extends TestCase
{
    #[Test]
    public function the_hash_uses_only_the_hotel_object_and_ignores_audit_metadata(): void
    {
        $hasher = new ContentHotelHasher;
        $first = '{"version":"1.0","auditData":{"processTime":"25","timestamp":"2026-09-29 10:00:00.000","requestHost":"h","serverId":"s","environment":"e","release":"r"},"hotel":{"code":712,"name":{"content":"Alua"}}}';
        $second = '{"auditData":{"timestamp":"2099-01-01 00:00:00.000","processTime":"99999","requestHost":"other","serverId":"other","environment":"other","release":"other"},"version":"9.9","hotel":{"name":{"content":"Alua"},"code":712}}';

        $this->assertSame($hasher->hash($first), $hasher->hash($second));
        $this->assertSame(64, strlen($hasher->hash($first)));
        $this->assertDoesNotMatchRegularExpression('/[^0-9a-f]/', $hasher->hash($first));
    }

    #[Test]
    public function key_order_does_not_change_the_hash(): void
    {
        $hasher = new ContentHotelHasher;
        $left = '{"hotel":{"code":1,"ranking":5,"name":{"content":"A"}}}';
        $right = '{"hotel":{"name":{"content":"A"},"ranking":5,"code":1}}';

        $this->assertSame($hasher->hash($left), $hasher->hash($right));
    }

    #[Test]
    public function numeric_padding_does_not_change_the_hash(): void
    {
        $hasher = new ContentHotelHasher;
        $padded = '{"hotel":{"coordinates":{"longitude":3.23084200000000000000,"latitude":39.36257000000000000000}}}';
        $plain = '{"hotel":{"coordinates":{"latitude":39.36257,"longitude":3.230842}}}';

        $this->assertSame($hasher->hash($padded), $hasher->hash($plain));
        $this->assertSame('3.230842', ContentHotelHasher::canonicalNumber('3.23084200000000000000'));
        $this->assertSame('39.36257', ContentHotelHasher::canonicalNumber('39.36257000000000000000'));
    }

    #[Test]
    public function a_hotel_content_change_changes_the_hash(): void
    {
        $hasher = new ContentHotelHasher;
        $original = '{"hotel":{"code":712,"name":{"content":"Alua"}}}';
        $changed = '{"hotel":{"code":712,"name":{"content":"Alua Updated"}}}';

        $this->assertNotSame($hasher->hash($original), $hasher->hash($changed));
    }
}
