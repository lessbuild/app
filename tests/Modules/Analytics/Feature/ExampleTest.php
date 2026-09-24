<?php

namespace Tests\Modules\Analytics\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_analytics_host_root_opens_the_dashboard(): void
    {
        $response = $this->get('/');

        if (config('platform.products.analytics.enabled') && filled(config('platform.products.analytics.host'))) {
            $response->assertRedirect('/dashboard');

            return;
        }

        $response->assertOk();
    }
}
