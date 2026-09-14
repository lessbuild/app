<?php

namespace Tests\Feature;

use App\Jobs\Repository\RollbackReleaseJob;
use App\Models\Build;
use App\Models\PreviewDeployment;
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
        $catalogObservedAt = now()->subHours(2)->startOfMinute();
        Size::query()->create(['slug' => 's-1', 'description' => 'Small', 'memory' => 1024, 'vcpus' => 1, 'disk' => 25, 'transfer' => 1, 'price_monthly' => 12, 'price_hourly' => 0.02, 'catalog_synced_at' => $catalogObservedAt]);
        $owner->servers()->firstOrFail()->update(['size' => 's-1']);

        $this->actingAs($owner)->get(route('costs.index'))
            ->assertOk()
            ->assertSee('$12.00')
            ->assertSee('No CPU sample')
            ->assertSee('Provider-catalog estimates')
            ->assertSee('Measured CPU telemetry')
            ->assertSee('Provider billing: not connected')
            ->assertSee('Linked to Application')
            ->assertSee($catalogObservedAt->toDayDateTimeString());
        $this->actingAs($owner)->patch(route('costs.update'), ['monthly_infrastructure_budget' => 100])->assertRedirect();
        $this->assertSame('100.00', $owner->currentOrganization->fresh()->monthly_infrastructure_budget);
    }

    public function test_cost_budget_denies_non_managers_before_validation_and_writing(): void
    {
        config(['billing.enforce_entitlements' => false]);
        [$owner] = $this->application();
        $viewer = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $organization->id]);

        $this->actingAs($viewer)->from(route('costs.index'))
            ->patch(route('costs.update'), ['monthly_infrastructure_budget' => 'not-a-number'])
            ->assertForbidden();

        $this->assertNull($organization->fresh()->monthly_infrastructure_budget);
    }

    public function test_cost_view_does_not_invent_a_price_for_an_unmapped_server_size(): void
    {
        config(['billing.enforce_entitlements' => false]);
        [$owner] = $this->application();
        $owner->servers()->firstOrFail()->update(['size' => 'unmapped-size']);

        $this->actingAs($owner)->get(route('costs.index'))
            ->assertOk()
            ->assertSee('$0.00')
            ->assertSee('Price source unavailable')
            ->assertSee('Unknown prices');
    }

    public function test_cost_view_shows_preview_quota_and_expiry_without_treating_quota_as_cost(): void
    {
        config([
            'billing.enforce_entitlements' => false,
            'billing.plans.free.limits.preview_deployments' => 3,
        ]);
        [$owner, $environment, $repository] = $this->application();
        $project = $owner->currentOrganization->projects()->firstOrFail();
        $project->update(['preview_enabled' => true, 'preview_ttl_hours' => 24]);
        $lastActivityAt = now()->subHours(2)->startOfMinute();
        $project->previews()->create([
            'source_repository_id' => $repository->id,
            'environment_id' => $environment->id,
            'website_id' => $repository->website_id,
            'repository_id' => $repository->id,
            'pull_request_number' => 42,
            'title' => 'Preview',
            'source_branch' => 'feature/costs',
            'revision' => str_repeat('a', 40),
            'status' => PreviewDeployment::STATUS_READY,
            'url' => 'preview.example.com',
            'last_activity_at' => $lastActivityAt,
        ]);

        $this->actingAs($owner)->get(route('costs.index'))
            ->assertOk()
            ->assertSee('Preview lifetime')
            ->assertSee('1 of 3 preview environments in use; quota is not a monetary limit.')
            ->assertSee('Application · PR #42')
            ->assertSee('24-hour configured lifetime')
            ->assertSee('Expires '.$lastActivityAt->copy()->addHours(24)->toDayDateTimeString());
    }

    public function test_expired_preview_is_a_review_only_recommendation(): void
    {
        config(['billing.enforce_entitlements' => false]);
        Queue::fake();
        [$owner, $environment, $repository] = $this->application();
        $project = $owner->currentOrganization->projects()->firstOrFail();
        $project->update(['preview_enabled' => true, 'preview_ttl_hours' => 24]);
        $preview = $project->previews()->create([
            'source_repository_id' => $repository->id,
            'environment_id' => $environment->id,
            'website_id' => $repository->website_id,
            'repository_id' => $repository->id,
            'pull_request_number' => 43,
            'title' => 'Expired preview',
            'source_branch' => 'feature/expired',
            'revision' => str_repeat('b', 40),
            'status' => PreviewDeployment::STATUS_READY,
            'url' => 'expired.example.com',
            'last_activity_at' => now()->subHours(25),
        ]);

        $this->actingAs($owner)->get(route('costs.index'))
            ->assertOk()
            ->assertSee('Past configured lifetime; cleanup is pending.')
            ->assertSee('Review preview');

        Queue::assertNothingPushed();
        $this->assertSame(PreviewDeployment::STATUS_READY, $preview->fresh()->status);
        $this->assertNull($preview->fresh()->closed_at);
    }

    public function test_cost_view_labels_shared_and_unallocated_server_relationships_without_splitting_costs(): void
    {
        config(['billing.enforce_entitlements' => false]);
        [$owner, $environment] = $this->application();
        $organization = $owner->currentOrganization;
        $sharedProject = $organization->projects()->create([
            'created_by' => $owner->id,
            'name' => 'Shared',
            'slug' => 'shared',
        ]);
        $sharedProject->environments()->create([
            'server_id' => $environment->server_id,
            'name' => 'Staging',
            'slug' => 'staging',
            'type' => 'staging',
            'branch' => 'main',
        ]);
        $owner->servers()->create([
            'provider_id' => $owner->providers()->where('provider', Provider::TYPE_DIGITALOCEAN)->value('id'),
            'name' => 'Unallocated',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        $this->actingAs($owner)->get(route('costs.index'))
            ->assertOk()
            ->assertSee('Shared across 2 projects')
            ->assertSee('No project attribution; cost remains at server level.');
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
