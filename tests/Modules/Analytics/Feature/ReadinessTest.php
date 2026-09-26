<?php

namespace Tests\Modules\Analytics\Feature;

use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_readiness_endpoint_checks_application_dependencies(): void
    {
        $this->get('/ready')->assertOk()->assertJson(['status' => 'ok']);
    }
}
