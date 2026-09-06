<?php

namespace Tests\Feature;

use App\Jobs\Repository\RollbackReleaseJob;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Size;
use App\Models\User;
use App\Models\Website;
use App\Services\AutomaticDeploymentRollback;
use App\Services\DeploymentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductImprovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployments_capture_a_preflight_risk_snapshot(): void
    {
        [$owner, $environment, $repository] = $this->application();

        $attributes = app(DeploymentRequest::class)->attributes($repository, $owner);

        $this->assertSame('review', $attributes['risk_assessment']['level']);
        $this->assertCount(8, $attributes['risk_assessment']['checks']);
        $this->assertSame('warning', collect($attributes['risk_assessment']['checks'])->firstWhere('name', 'Health verification')['status']);
        $this->assertSame('warning', collect($attributes['risk_assessment']['checks'])->firstWhere('name', 'Push automation')['status']);
    }

    public function test_failed_activated_release_can_queue_automatic_rollback(): void
    {
        Queue::fake();
        [$owner, $environment, $repository] = $this->application();
        $environment->update(['automatic_rollback' => true]);
        $source = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED, 'trigger_source' => Build::TRIGGER_MANUAL,
            'revision' => str_repeat('a', 40), 'release_name' => 'release-1', 'release_path' => '/var/www/app/releases/release-1',
            'environment_id' => $environment->id, 'requested_by' => $owner->id, 'finished_at' => now()->subMinute(),
        ]);
        $failed = $repository->builds()->create([
            'status' => Build::STATUS_FAILED, 'trigger_source' => Build::TRIGGER_MANUAL,
            'revision' => str_repeat('b', 40), 'environment_id' => $environment->id,
            'requested_by' => $owner->id, 'activated_at' => now(), 'finished_at' => now(),
        ]);

        $rollback = app(AutomaticDeploymentRollback::class)->attempt($failed);

        $this->assertNotNull($rollback);
        $this->assertSame(Build::TRIGGER_ROLLBACK, $rollback->trigger_source);
        $this->assertSame($source->id, $rollback->rolled_back_from_build_id);
        $this->assertSame($rollback->id, $failed->fresh()->automatic_rollback_build_id);
        Queue::assertPushed(RollbackReleaseJob::class);
    }

    public function test_cost_view_is_scoped_and_budget_is_managed_by_admins(): void
    {
        config(['billing.enforce_entitlements' => false]);
        [$owner] = $this->application();
        Size::query()->create(['slug' => 's-1', 'description' => 'Small', 'memory' => 1024, 'vcpus' => 1, 'disk' => 25, 'transfer' => 1, 'price_monthly' => 12, 'price_hourly' => 0.02]);
        $owner->servers()->firstOrFail()->update(['size' => 's-1']);

        $this->actingAs($owner)->get(route('costs.index'))->assertOk()->assertSee('$12.00')->assertSee('No CPU sample');
        $this->actingAs($owner)->patch(route('costs.update'), ['monthly_infrastructure_budget' => 100])->assertRedirect();
        $this->assertSame('100.00', $owner->currentOrganization->fresh()->monthly_infrastructure_budget);
    }

    private function application(): array
    {
        $owner = User::factory()->create();
        $cloud = $owner->providers()->create(['name' => 'Cloud', 'provider' => Provider::TYPE_DIGITALOCEAN, 'token' => 'cloud', 'description' => 'Cloud']);
        $source = $owner->providers()->create(['name' => 'GitHub', 'provider' => Provider::TYPE_GITHUB, 'token' => 'source', 'description' => 'Source']);
        $server = $owner->servers()->create(['provider_id' => $cloud->id, 'name' => 'Production', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $owner->websites()->create(['server_id' => $server->id, 'name' => 'Application', 'description' => 'Website', 'environment' => '', 'url' => 'app.example.com', 'provisioning_status' => Website::STATUS_ACTIVE, 'release_retention' => 5]);
        $repository = $owner->repositories()->create(['provider_id' => $source->id, 'website_id' => $website->id, 'name' => 'Application', 'url' => 'github.com/example/application.git', 'branch' => 'main', 'description' => 'Source']);
        $project = $owner->currentOrganization->projects()->create(['created_by' => $owner->id, 'name' => 'Application', 'slug' => 'application']);
        $environment = $project->environments()->create(['server_id' => $server->id, 'website_id' => $website->id, 'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);

        return [$owner, $environment, $repository];
    }
}
