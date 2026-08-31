<?php

namespace Tests\Feature;

use App\Services\ApplicationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_when_the_application_is_ready(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ready'])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_it_reports_unavailability_without_exposing_internal_details(): void
    {
        $readiness = $this->mock(ApplicationReadiness::class);
        $readiness->shouldReceive('isReady')->once()->andReturnFalse();

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable'])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
