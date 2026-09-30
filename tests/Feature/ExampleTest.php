<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_loads(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Rento HBX Lab')
            ->assertSee('HBX TEST ENVIRONMENT')
            ->assertDontSee('test-secret');
    }
}
