<?php

namespace Tests\Feature;

use App\Actions\Repository\CreateDeploymentObservationAction;
use App\Actions\Repository\RunDeploymentObservationAction;
use App\Data\WebsiteHealthProbeResult;
use App\Enums\DeploymentObservationStatus;
use App\Jobs\Repository\ObserveDeploymentJob;
use App\Models\Build;
use App\Models\DeploymentObservation;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\DeploymentRequest;
use App\Services\RepositoryDeploymentPlan;
use App\Services\WebsiteHealthProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DeploymentObservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

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

        Queue::assertPushed(ObserveDeploymentJob::class, 1);

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
        Queue::assertPushed(ObserveDeploymentJob::class, 1);
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

    public function test_worker_persists_successful_checks_without_touching_periodic_health_state(): void
    {
        [$owner, , $environment, $repository, $website, $server] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 5]);
        $build = $this->build($repository, $environment, $owner);
        $build->update(['status' => Build::STATUS_SUCCEEDED]);
        $observation = app(CreateDeploymentObservationAction::class)->handle($build);

        $probe = Mockery::mock(WebsiteHealthProbe::class);
        $probe->shouldReceive('probe')->once()->withArgs(function (Website $target) use ($website, $server): bool {
            return $target->url === $website->url
                && $target->health_check_path === $website->health_check_path
                && $target->server_id === $server->id
                && $target->server->is($server);
        })->andReturn(new WebsiteHealthProbeResult(true, null, 200, 125));
        $this->app->instance(WebsiteHealthProbe::class, $probe);

        app(RunDeploymentObservationAction::class)->handle($observation->id);

        $observation->refresh();
        $this->assertSame(DeploymentObservationStatus::Observing->value, $observation->status);
        $this->assertSame(1, $observation->attempts);
        $this->assertSame(1, $observation->successful_checks);
        $this->assertSame(200, $observation->last_http_status);
        $this->assertSame(125, $observation->last_duration_ms);
        $this->assertNotNull($observation->next_check_at);
        $this->assertNull($observation->completed_at);
        $this->assertNull($website->fresh()->health_last_checked_at);
        $this->assertDatabaseCount('website_health_checks', 0);
    }

    public function test_failed_probe_is_terminal_and_keeps_remote_error_bounded(): void
    {
        [$owner, , $environment, $repository] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 5]);
        $build = $this->build($repository, $environment, $owner);
        $build->update(['status' => Build::STATUS_SUCCEEDED]);
        $observation = app(CreateDeploymentObservationAction::class)->handle($build);

        $probe = Mockery::mock(WebsiteHealthProbe::class);
        $probe->shouldReceive('probe')->once()->andReturn(new WebsiteHealthProbeResult(
            successful: false,
            error: str_repeat('remote detail ', 100),
            httpStatus: 503,
            durationMs: 250,
        ));
        $this->app->instance(WebsiteHealthProbe::class, $probe);

        app(RunDeploymentObservationAction::class)->handle($observation->id);

        $observation->refresh();
        $this->assertSame(DeploymentObservationStatus::Failed->value, $observation->status);
        $this->assertSame(503, $observation->last_http_status);
        $this->assertSame(250, $observation->last_duration_ms);
        $this->assertSame(500, strlen((string) $observation->last_error));
        $this->assertNull($observation->claim_token);
        $this->assertNull($observation->next_check_at);
        $this->assertNotNull($observation->completed_at);
    }

    public function test_unexpected_worker_failures_retry_with_a_bounded_terminal_outcome(): void
    {
        [$owner, , $environment, $repository] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 5]);
        $build = $this->build($repository, $environment, $owner);
        $build->update(['status' => Build::STATUS_SUCCEEDED]);
        $observation = app(CreateDeploymentObservationAction::class)->handle($build);

        $probe = Mockery::mock(WebsiteHealthProbe::class);
        $probe->shouldReceive('probe')->times(3)->andThrow(new RuntimeException('temporary worker failure'));
        $this->app->instance(WebsiteHealthProbe::class, $probe);

        foreach ([1, 2, 3] as $attempt) {
            try {
                app(RunDeploymentObservationAction::class)->handle($observation->id, $attempt, 3);
                $this->fail('The simulated worker failure should be rethrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame('temporary worker failure', $exception->getMessage());
            }

            $observation->refresh();
            $this->assertSame($attempt, $observation->attempts);
        }

        $observation->refresh();
        $this->assertSame(DeploymentObservationStatus::Failed->value, $observation->status);
        $this->assertSame('temporary worker failure', $observation->last_error);
        $this->assertNull($observation->claim_token);
        $this->assertNull($observation->next_check_at);
        $this->assertNotNull($observation->completed_at);
    }

    public function test_stale_worker_result_cannot_overwrite_a_newer_claim(): void
    {
        [$owner, , $environment, $repository] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 5]);
        $build = $this->build($repository, $environment, $owner);
        $build->update(['status' => Build::STATUS_SUCCEEDED]);
        $observation = app(CreateDeploymentObservationAction::class)->handle($build);

        $probe = Mockery::mock(WebsiteHealthProbe::class);
        $probe->shouldReceive('probe')->once()->andReturnUsing(function () use ($observation): WebsiteHealthProbeResult {
            DeploymentObservation::query()->whereKey($observation->id)->update([
                'claim_token' => 'newer-claim-token',
                'lease_expires_at' => now()->addMinute(),
            ]);

            return new WebsiteHealthProbeResult(true, null, 200, 100);
        });
        $this->app->instance(WebsiteHealthProbe::class, $probe);

        app(RunDeploymentObservationAction::class)->handle($observation->id);

        $observation->refresh();
        $this->assertSame(DeploymentObservationStatus::Observing->value, $observation->status);
        $this->assertSame('newer-claim-token', $observation->claim_token);
        $this->assertSame(0, $observation->successful_checks);
        $this->assertNull($observation->last_checked_at);
    }

    public function test_target_changed_during_probe_supersedes_the_observation(): void
    {
        [$owner, , $environment, $repository, $website] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 5]);
        $build = $this->build($repository, $environment, $owner);
        $build->update(['status' => Build::STATUS_SUCCEEDED]);
        $observation = app(CreateDeploymentObservationAction::class)->handle($build);

        $probe = Mockery::mock(WebsiteHealthProbe::class);
        $probe->shouldReceive('probe')->once()->andReturnUsing(function () use ($website): WebsiteHealthProbeResult {
            $website->update(['health_check_path' => '/changed-after-claim']);

            return new WebsiteHealthProbeResult(true, null, 200, 100);
        });
        $this->app->instance(WebsiteHealthProbe::class, $probe);

        app(RunDeploymentObservationAction::class)->handle($observation->id);

        $observation->refresh();
        $this->assertSame(DeploymentObservationStatus::Superseded->value, $observation->status);
        $this->assertNull($observation->claim_token);
        $this->assertNull($observation->next_check_at);
        $this->assertNull($observation->last_checked_at);
        $this->assertNotNull($observation->completed_at);
    }

    public function test_expired_observations_are_closed_without_remote_work(): void
    {
        [$owner, , $environment, $repository] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 5]);
        $build = $this->build($repository, $environment, $owner);
        $build->update(['status' => Build::STATUS_SUCCEEDED]);
        $observation = app(CreateDeploymentObservationAction::class)->handle($build);
        $observation->update([
            'deadline_at' => now()->subMinute(),
            'next_check_at' => now()->subMinute(),
        ]);

        $probe = Mockery::mock(WebsiteHealthProbe::class);
        $probe->shouldNotReceive('probe');
        $this->app->instance(WebsiteHealthProbe::class, $probe);

        app(RunDeploymentObservationAction::class)->handle($observation->id);

        $observation->refresh();
        $this->assertSame(DeploymentObservationStatus::Expired->value, $observation->status);
        $this->assertSame('The observation window expired before it completed.', $observation->last_error);
        $this->assertNotNull($observation->completed_at);
    }

    public function test_scheduler_queues_due_and_expired_leases_but_not_future_checks(): void
    {
        [$owner, , $environment, $repository] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 5]);
        $build = $this->build($repository, $environment, $owner);
        $build->update(['status' => Build::STATUS_SUCCEEDED]);
        $observation = app(CreateDeploymentObservationAction::class)->handle($build);
        $observation->update(['next_check_at' => now()->addMinute()]);

        Queue::fake();
        Artisan::call('buildpusher:deployments:observe');
        Queue::assertNothingPushed();

        $observation->update(['next_check_at' => now()->subMinute()]);
        Artisan::call('buildpusher:deployments:observe');

        Queue::assertPushed(ObserveDeploymentJob::class, function (ObserveDeploymentJob $job) use ($observation): bool {
            return $job->observationId === $observation->id;
        });
        $this->assertStringContainsString('Queued 1 deployment observation(s).', Artisan::output());
    }

    public function test_scheduler_queues_an_observation_with_an_expired_lease(): void
    {
        [$owner, , $environment, $repository] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 5]);
        $build = $this->build($repository, $environment, $owner);
        $build->update(['status' => Build::STATUS_SUCCEEDED]);
        $observation = app(CreateDeploymentObservationAction::class)->handle($build);
        $observation->update([
            'status' => DeploymentObservation::STATUS_OBSERVING,
            'next_check_at' => null,
            'lease_expires_at' => now()->subMinute(),
        ]);

        Queue::fake();
        Artisan::call('buildpusher:deployments:observe');

        Queue::assertPushed(ObserveDeploymentJob::class, function (ObserveDeploymentJob $job) use ($observation): bool {
            return $job->observationId === $observation->id;
        });
        $this->assertStringContainsString('Queued 1 deployment observation(s).', Artisan::output());
    }

    public function test_build_detail_shows_bounded_observation_evidence_without_claim_or_remote_error(): void
    {
        [$owner, , $environment, $repository] = $this->fixture();
        config(['billing.plans.free.entitlements' => ['deployments', 'releases', 'approvals', 'monitoring']]);
        $environment->update(['post_deployment_observation_minutes' => 5]);
        $build = $this->build($repository, $environment, $owner);
        $build->update(['status' => Build::STATUS_SUCCEEDED]);
        $observation = app(CreateDeploymentObservationAction::class)->handle($build);
        $observation->update([
            'status' => DeploymentObservation::STATUS_FAILED,
            'successful_checks' => 2,
            'last_http_status' => 503,
            'last_checked_at' => now(),
            'last_error' => 'remote secret response that must not be rendered',
            'claim_token' => 'private-claim-token',
        ]);

        $this->actingAs($owner)
            ->get(route('builds.show', $build))
            ->assertSuccessful()
            ->assertSee('Post-deployment observation')
            ->assertSee('Revision-linked verification')
            ->assertSee('Successful checks')
            ->assertSee('503')
            ->assertSee('The deployment did not complete post-deployment verification.')
            ->assertDontSee('remote secret response that must not be rendered')
            ->assertDontSee('private-claim-token');
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
