<?php

namespace Tests\Modules\Analytics\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_endpoint_checks_application_dependencies(): void
    {
        $this->get('/ready')->assertOk()->assertJson(['status' => 'ok']);
    }
}
