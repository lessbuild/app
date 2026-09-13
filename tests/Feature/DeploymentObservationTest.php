<?php

namespace Tests\Feature;

use App\Actions\Repository\CreateDeploymentObservationAction;
use App\Enums\DeploymentObservationStatus;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\DeploymentRequest;
use App\Services\RepositoryDeploymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DeploymentObservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_observation_window_is_optional_and_requires_monitoring_entitlement(): void
    {
        [$owner, , $environment] = $this->fixture();
        $payload = $this->environmentPayload($environment);
        config(['billing.enforce_entitlements' => true]);

        $this->actingAs($owner)
            ->patch(route('environments.update', $environment), [
                ...$payload,
                'post_deployment_observation_minutes' => 10,
            ])
            ->assertSessionHasErrors('plan');
        $this->assertNull($environment->fresh()->post_deployment_observation_minutes);

        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $this->actingAs($owner)
            ->patch(route('environments.update', $environment), [
                ...$payload,
                'post_deployment_observation_minutes' => 10,
            ])
            ->assertSessionHas('success', 'Environment updated.');
        $this->assertSame(10, $environment->fresh()->post_deployment_observation_minutes);

        $this->actingAs($owner)
            ->patch(route('environments.update', $environment), [
                ...$payload,
                'post_deployment_observation_minutes' => 7,
            ])
            ->assertSessionHasErrors('post_deployment_observation_minutes');
        $this->assertSame(10, $environment->fresh()->post_deployment_observation_minutes);
    }

    public function test_deployment_request_captures_the_non_secret_target_snapshot(): void
    {
        [$owner, , $environment, $repository, $website, $server] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 15]);

        $attributes = app(DeploymentRequest::class)->attributes($repository, $owner);

        $this->assertSame([
            'duration_minutes' => 15,
            'website_id' => $website->id,
            'server_id' => $server->id,
            'url' => $website->url,
            'path' => $website->health_check_path,
        ], $attributes['environment_payload']['post_deployment_observation']);

        $website->update(['url' => 'changed.example.com', 'health_check_path' => '/changed']);
        $this->assertSame('app.example.com', $attributes['environment_payload']['post_deployment_observation']['url']);
        $this->assertSame('/health/ready', $attributes['environment_payload']['post_deployment_observation']['path']);
    }

    public function test_successful_revision_bound_build_creates_one_pending_observation(): void
    {
        [$owner, , $environment, $repository] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 10]);
        $build = $this->build($repository, $environment, $owner);
        $finalStage = app(RepositoryDeploymentPlan::class)->finalStage();

        $this->post(URL::signedRoute('callbacks.build.status', $build), ['status' => $finalStage])
            ->assertNoContent();

        $observation = $build->deploymentObservation()->sole();
        $this->assertSame(DeploymentObservationStatus::Pending->value, $observation->status);
        $this->assertSame($build->revision, $observation->revision);
        $this->assertSame($repository->website_id, $observation->website_id);
        $this->assertSame(10, $observation->duration_minutes);
        $this->assertNotNull($observation->deadline_at);
        $this->assertSame(1, $build->deploymentObservation()->count());

        $this->post(URL::signedRoute('callbacks.build.status', $build), ['status' => $finalStage])
            ->assertNoContent();
        $this->assertSame(1, $build->deploymentObservation()->count());
    }

    public function test_newer_successful_deployment_supersedes_an_active_observation(): void
    {
        [$owner, , $environment, $repository] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 10]);
        $first = $this->build($repository, $environment, $owner, str_repeat('a', 40));
        $second = $this->build($repository, $environment, $owner, str_repeat('b', 40));
        $action = app(CreateDeploymentObservationAction::class);

        $first->update(['status' => Build::STATUS_SUCCEEDED]);
        $second->update(['status' => Build::STATUS_SUCCEEDED]);
        $action->handle($first);
        $action->handle($second);

        $this->assertSame(DeploymentObservationStatus::Superseded->value, $first->deploymentObservation()->sole()->status);
        $this->assertSame(DeploymentObservationStatus::Pending->value, $second->deploymentObservation()->sole()->status);
        $this->assertNotNull($first->deploymentObservation()->sole()->completed_at);
    }

    public function test_observation_is_not_created_when_the_captured_target_has_changed(): void
    {
        [$owner, , $environment, $repository, $website] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 10]);
        $attributes = app(DeploymentRequest::class)->attributes($repository, $owner);
        $website->update(['health_check_path' => '/new-health']);
        $build = $repository->builds()->create([
            ...$attributes,
            'status' => Build::STATUS_SUCCEEDED,
            'revision' => str_repeat('c', 40),
        ]);

        $this->assertNull(app(CreateDeploymentObservationAction::class)->handle($build));
        $this->assertDatabaseCount('deployment_observations', 0);
    }

    /** @return array{User, Project, Environment, Repository, Website, Server} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'provider-token',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Application server',
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'mysql-root-secret',
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'health_check_enabled' => true,
            'health_check_path' => '/health/ready',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'name' => 'Application',
            'slug' => 'application',
            'created_by' => $owner->id,
        ]);
        $environment = $project->environments()->create([
            'name' => 'Production',
            'slug' => 'production',
            'type' => 'production',
            'branch' => 'main',
            'server_id' => $server->id,
            'website_id' => $website->id,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);

        return [$owner, $project, $environment, $repository, $website, $server];
    }

    /** @return array<string, mixed> */
    private function environmentPayload(Environment $environment): array
    {
        return [
            'name' => $environment->name,
            'type' => $environment->type,
            'branch' => $environment->branch,
            'runtime_type' => 'php',
            'is_protected' => '1',
            'requires_deployment_approval' => '0',
            'minimum_replicas' => 1,
            'maximum_replicas' => 1,
            'hibernate_after_minutes' => null,
        ];
    }

    private function build(
        Repository $repository,
        Environment $environment,
        User $owner,
        string $revision = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ): Build {
        $attributes = app(DeploymentRequest::class)->attributes($repository, $owner);

        return $repository->builds()->create([
            ...$attributes,
            'environment_id' => $environment->id,
            'status' => Build::STATUS_RUNNING,
            'revision' => $revision,
            'started_at' => now(),
        ]);
    }
}
