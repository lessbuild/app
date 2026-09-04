<?php

namespace Tests\Feature;

use App\Jobs\Web\CheckWebsiteHealthJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteHealthCheck;
use App\Services\ManagedSsh;
use App\Services\ProviderHealthMonitor;
use App\Services\Runner;
use App\Services\WebsiteHealthMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AutomaticMonitoringControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_paused_provider_is_skipped_automatically_but_can_be_checked_manually(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'Offline GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-secret',
            'description' => 'Checked only on demand',
            'connection_monitoring_enabled' => false,
        ]);

        Artisan::call('lessbuild:providers:health', ['--provider' => [$provider->id]]);

        $this->assertStringContainsString('Checked 0 provider(s)', Artisan::output());
        $this->assertNull($provider->fresh()->connection_checked_at);
        Http::assertNothingSent();

        Http::fake(['https://api.github.com/user' => Http::response(['login' => 'owner'])]);
        $this->actingAs($owner)
            ->post(route('providers.connection.test', $provider))
            ->assertRedirect(route('providers.show', $provider))
            ->assertSessionHas('provider_connection', fn (array $result): bool => $result['successful']);

        $provider->refresh();
        $this->assertFalse($provider->connection_monitoring_enabled);
        $this->assertSame(Provider::CONNECTION_HEALTHY, $provider->connection_status);
        $this->assertNotNull($provider->connection_checked_at);
        Http::assertSentCount(1);
    }

    public function test_automatic_provider_result_is_discarded_when_monitoring_is_paused_in_flight(): void
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-secret',
            'description' => 'Provider',
        ]);
        Http::fake(function () use ($provider) {
            $provider->update(['connection_monitoring_enabled' => false]);

            return Http::response(['login' => 'owner']);
        });

        $result = app(ProviderHealthMonitor::class)->check($provider, automatic: true);

        $this->assertFalse($result['recorded']);
        $provider->refresh();
        $this->assertFalse($provider->connection_monitoring_enabled);
        $this->assertNull($provider->connection_status);
        $this->assertNull($provider->connection_checked_at);
    }

    public function test_paused_website_is_skipped_automatically_but_manual_job_still_checks_it(): void
    {
        [$owner, $server, $website] = $this->websiteInfrastructure();
        $website->update(['health_monitoring_enabled' => false]);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');
        $this->app->instance(Runner::class, $runner);

        Artisan::call('lessbuild:websites:health', ['--website' => [$website->id]]);

        $this->assertStringContainsString('Checked 0 website(s)', Artisan::output());
        $this->assertNull($website->fresh()->health_last_checked_at);

        $this->app->instance(Runner::class, $this->successfulRunner());
        (new CheckWebsiteHealthJob($website->id))->handle(app(WebsiteHealthMonitor::class));

        $website->refresh();
        $this->assertFalse($website->health_monitoring_enabled);
        $this->assertSame(Website::HEALTH_HEALTHY, $website->health_status);
        $this->assertNotNull($website->health_last_checked_at);
        $this->assertDatabaseHas('website_health_checks', [
            'website_id' => $website->id,
            'successful' => true,
            'source' => WebsiteHealthCheck::SOURCE_MANUAL,
            'http_status' => 200,
            'duration_ms' => 100,
        ]);

        $this->actingAs($owner)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertSee('Automatic monitoring')
            ->assertSee('Paused')
            ->assertSee('Check health now');
        $this->assertSame($server->id, $website->server_id);
    }

    public function test_owner_can_pause_and_resume_automatic_monitoring_in_resource_forms(): void
    {
        [$owner, $server, $website] = $this->websiteInfrastructure();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-secret',
            'description' => 'Provider',
        ]);

        $this->actingAs($owner)->patch(route('providers.update', $provider), [
            'name' => $provider->name,
            'provider' => $provider->provider,
            'description' => $provider->description,
            'connection_monitoring_enabled' => '0',
        ])->assertRedirect(route('providers.show', $provider));
        $this->assertFalse($provider->fresh()->connection_monitoring_enabled);
        $this->get(route('providers.show', $provider))
            ->assertSuccessful()
            ->assertSee('Automatic monitoring paused');

        $this->patch(route('websites.update', $website), [
            ...$this->websitePayload($server),
            'health_monitoring_enabled' => '0',
        ])->assertRedirect(route('websites.show', $website));
        $this->assertFalse($website->fresh()->health_monitoring_enabled);

        $this->patch(route('websites.update', $website), [
            ...$this->websitePayload($server),
            'health_monitoring_enabled' => '1',
        ])->assertRedirect(route('websites.show', $website));
        $this->assertTrue($website->fresh()->health_monitoring_enabled);
    }

    private function successfulRunner(): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getOutput')->once()->andReturn('200 0.100000');
        $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }

    /** @return array{User, Server, Website} */
    private function websiteInfrastructure(): array
    {
        $owner = User::factory()->create();
        $server = $owner->servers()->create([
            'name' => 'Production',
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'mysql-secret',
        ]);
        $website = $owner->websites()->create([
            ...$this->websitePayload($server),
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$owner, $server, $website];
    }

    /** @return array<string, mixed> */
    private function websitePayload(Server $server): array
    {
        return [
            'server_id' => $server->id,
            'name' => 'Application',
            'url' => 'app.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'health_check_enabled' => '1',
            'health_monitoring_enabled' => '1',
            'health_check_path' => '/health',
            'release_retention' => 5,
        ];
    }
}
