<?php

namespace Tests\Feature;

use App\Services\ExternalMonitoring;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalMonitoringTest extends TestCase
{
    public function test_production_requires_both_external_monitoring_destinations(): void
    {
        config(['app.env' => 'production', 'monitoring.heartbeat_url' => null, 'monitoring.status_url' => null]);
        $this->artisan('buildpusher:monitoring:heartbeat')->assertFailed();

        $check = app(ExternalMonitoring::class)->configurationCheck();
        $this->assertFalse($check['passed']);
        $this->assertStringNotContainsString('http', $check['detail']);
    }

    public function test_heartbeat_uses_the_private_configured_url_without_exposing_it(): void
    {
        Http::fake(['monitor.example.net/*' => Http::response('', 204)]);
        config([
            'app.env' => 'production',
            'monitoring.heartbeat_url' => 'https://monitor.example.net/secret-heartbeat-token',
            'monitoring.status_url' => 'https://status.buildpusher.com',
        ]);

        $this->artisan('buildpusher:monitoring:heartbeat')
            ->doesntExpectOutputToContain('secret-heartbeat-token')
            ->assertSuccessful();
        Http::assertSent(fn ($request): bool => $request->url() === 'https://monitor.example.net/secret-heartbeat-token'
            && $request->hasHeader('User-Agent', 'BuildPusher-heartbeat'));
    }

    public function test_production_rejects_insecure_or_same_host_monitoring_destinations(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://buildpusher.com',
            'monitoring.heartbeat_url' => 'http://monitor.example.net/heartbeat',
            'monitoring.status_url' => 'https://buildpusher.com/status',
        ]);

        $this->assertFalse(app(ExternalMonitoring::class)->configurationCheck()['passed']);
        $this->artisan('buildpusher:monitoring:heartbeat')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_acceptance_option_verifies_both_independent_destinations_without_exposing_them(): void
    {
        Http::fake([
            'monitor.example.net/*' => Http::response('', 204),
            'status.buildpusher.com/*' => Http::response('<html>Status</html>', 200),
        ]);
        config([
            'app.env' => 'production',
            'app.url' => 'https://buildpusher.com',
            'monitoring.heartbeat_url' => 'https://monitor.example.net/private-heartbeat-token',
            'monitoring.status_url' => 'https://status.buildpusher.com/',
        ]);

        $this->assertTrue(app(ExternalMonitoring::class)->configurationCheck()['passed']);
        $this->artisan('buildpusher:monitoring:heartbeat', ['--verify-status' => true])
            ->expectsOutput('External monitoring heartbeat delivered.')
            ->expectsOutput('Independent status page is reachable.')
            ->doesntExpectOutputToContain('private-heartbeat-token')
            ->assertSuccessful();
        Http::assertSentCount(2);
    }
}
