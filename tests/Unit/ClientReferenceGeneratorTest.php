<?php

namespace Tests\Unit;

use App\Services\HBX\ClientReferenceGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientReferenceGeneratorTest extends TestCase
{
    #[Test]
    public function generated_references_match_the_hbx_size_and_shape(): void
    {
        Carbon::setTestNow('2026-09-27 15:00:00');

        $generator = new ClientReferenceGenerator;
        $references = [];

        for ($index = 0; $index < 30; $index++) {
            $reference = $generator->generate();
            $references[] = $reference;

            $this->assertGreaterThanOrEqual(1, strlen($reference));
            $this->assertLessThanOrEqual(20, strlen($reference));
            $this->assertSame(20, strlen($reference));
            $this->assertMatchesRegularExpression('/^RENTO-260927-[A-Z0-9]{7}$/', $reference);
            $generator->validate($reference);
        }

        $this->assertCount(30, array_unique($references));
    }

    #[Test]
    public function the_rejected_long_reference_fails_validation(): void
    {
        $this->expectException(ValidationException::class);

        (new ClientReferenceGenerator)->validate('RENTO-LOCAL-1790505572-RDVZYS');
    }
}
