<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeploymentControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_lock_deployments_and_configure_a_weekly_window(): void
    {
        [$owner, $environment] = $this->environment();

        $this->actingAs($owner)->patch(route('environments.deployment-controls.update', $environment), [
            'deployment_locked' => '1',
            'deployment_lock_reason' => 'Release freeze',
            'deployment_window_enabled' => '1',
            'deployment_window_days' => [1, 2, 3, 4, 5],
            'deployment_window_start' => '09:00',
            'deployment_window_end' => '17:00',
            'deployment_window_timezone' => 'Europe/London',
        ])->assertRedirect();

        $environment->refresh();
        $this->assertNotNull($environment->deployment_locked_at);
        $this->assertSame($owner->id, $environment->deployment_locked_by);
        $this->assertSame('Release freeze', $environment->deploymentBlockReason());
        $this->assertSame([1, 2, 3, 4, 5], $environment->deployment_window_days);
    }

    public function test_maintenance_window_handles_normal_and_overnight_ranges(): void
    {
        [, $environment] = $this->environment();
        $environment->forceFill([
            'deployment_window_days' => [1],
            'deployment_window_start' => '09:00:00',
            'deployment_window_end' => '17:00:00',
            'deployment_window_timezone' => 'UTC',
        ]);

        $this->assertNull($environment->deploymentBlockReason(Carbon::parse('2026-09-07 10:00:00', 'UTC')));
        $this->assertNotNull($environment->deploymentBlockReason(Carbon::parse('2026-09-07 18:00:00', 'UTC')));

        $environment->forceFill([
            'deployment_window_start' => '22:00:00',
            'deployment_window_end' => '02:00:00',
        ]);
        $this->assertNull($environment->deploymentBlockReason(Carbon::parse('2026-09-07 23:00:00', 'UTC')));
        $this->assertNull($environment->deploymentBlockReason(Carbon::parse('2026-09-08 01:00:00', 'UTC')));
        $this->assertNotNull($environment->deploymentBlockReason(Carbon::parse('2026-09-08 03:00:00', 'UTC')));
    }

    public function test_manual_deployment_is_refused_while_environment_is_locked(): void
    {
        [$owner, $environment, $repository] = $this->environment();
        $environment->update([
            'deployment_locked_at' => now(),
            'deployment_locked_by' => $owner->id,
            'deployment_lock_reason' => 'Incident response',
        ]);

        $this->actingAs($owner)->post(route('repositories.deploy', $repository))
            ->assertRedirect()
            ->assertSessionHas('error', 'Incident response');
        $this->assertDatabaseCount('builds', 0);
    }

    private function environment(): array
    {
        $owner = User::factory()->create();
        $cloud = $owner->providers()->create([
            'name' => 'Cloud', 'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'cloud', 'description' => 'Cloud',
        ]);
        $source = $owner->providers()->create([
            'name' => 'GitHub', 'provider' => Provider::TYPE_GITHUB,
            'token' => 'source', 'description' => 'Source',
        ]);
        $server = $owner->servers()->create([
            'provider_id' => $cloud->id,
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $source->id,
            'website_id' => $website->id,
            'name' => 'Application',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Source',
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id,
            'name' => 'Application',
            'slug' => 'application',
        ]);
        $environment = $project->environments()->create([
            'server_id' => $server->id,
            'website_id' => $website->id,
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
            'branch' => 'main',
        ]);

        return [$owner, $environment, $repository];
    }
}
