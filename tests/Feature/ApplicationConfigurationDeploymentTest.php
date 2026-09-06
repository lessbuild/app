<?php

namespace Tests\Feature;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ApplicationConfigurationBuilds;
use App\Services\ApplicationConfigurationDelivery;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationResults;
use App\Services\ApplicationConfigurationReviews;
use App\Services\DeploymentRequest;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApplicationConfigurationDeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_persists_one_immutable_deployment_intent_and_preserves_approval(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $provider = $user->providers()->create(['name' => 'GitHub', 'provider' => 'github', 'token' => 'secret', 'description' => 'Git']);
        $server = $user->servers()->create(['name' => 'Test', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'App', 'url' => 'app.test', 'description' => 'Test', 'environment' => '', 'provisioning_status' => Website::STATUS_ACTIVE]);
        $repository = $user->repositories()->create(['provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'App', 'url' => 'github.com/example/app.git', 'branch' => 'main', 'description' => 'Test']);
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $environment = $project->environments()->create(['name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'requires_deployment_approval' => true]);
        $yaml = "version: 2\nenvironments:\n  staging:\n    adopt: true\n    type: staging\n    placement: site\n    runtime:\n      type: php\n      build_command: reviewed-command\n    deploy:\n      repository: app\n";
        $review = app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml,
            ['placements' => ['site' => $website->id], 'repositories' => ['app' => $repository->id]]);
        $service = app(ApplicationConfigurationReconciler::class);
        $application = $service->apply($review, $user);
        $this->assertSame('awaiting_dispatch', $application->status);
        $operation = $application->operations()->firstOrFail();
        $this->assertSame('pending', $operation->status);
        $this->assertSame(Build::STATUS_AWAITING_APPROVAL, $operation->payload['attributes']['status']);
        $this->assertSame('reviewed-command', $operation->payload['attributes']['environment_payload']['runtime']['build_command']);
        $environment->update(['build_command' => 'later-command']);
        $this->assertSame('reviewed-command', $operation->fresh()->payload['attributes']['environment_payload']['runtime']['build_command']);
        $this->assertSame($application->id, $service->apply($review, $user)->id);
        $this->assertDatabaseCount('configuration_operations', 1);
        $this->assertDatabaseCount('builds', 0);
        $duplicateReview = app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml,
            ['placements' => ['site' => $website->id], 'repositories' => ['app' => $repository->id]]);
        $duplicateApplication = $service->apply($duplicateReview, $user);
        $this->assertNotSame($application->id, $duplicateApplication->id);
        $this->assertSame($operation->id, $duplicateApplication->relatedOperations()->firstOrFail()->id);
        $this->assertDatabaseCount('configuration_operations', 1);
        $builds = app(ApplicationConfigurationBuilds::class);
        $build = $builds->prepare($operation);
        $this->assertNotNull($build);
        $this->assertSame(Build::STATUS_AWAITING_APPROVAL, $build->status);
        $this->assertSame('reviewed-command', $build->environment_payload['runtime']['build_command']);
        $this->assertSame($build->id, $builds->prepare($operation)->id);
        $this->assertDatabaseCount('builds', 1);
        $this->assertSame('build_created', $operation->fresh()->status);
        Queue::assertNothingPushed();
        app(ApplicationConfigurationDelivery::class)->deliver($operation);
        $this->assertSame('awaiting_approval', $operation->fresh()->status);
        Queue::assertNothingPushed();
        $build->update(['status' => Build::STATUS_QUEUED]);
        app(ApplicationConfigurationDelivery::class)->deliver($operation);
        $this->assertSame(Build::STATUS_AWAITING_APPROVAL, $build->fresh()->status);
        Queue::assertNothingPushed();
        $build->refresh()->update(['status' => Build::STATUS_QUEUED, 'approved_at' => now(), 'approved_by' => $user->id]);
        $environment->update(['deployment_locked_at' => now(), 'deployment_locked_by' => $user->id]);
        app(ApplicationConfigurationDelivery::class)->deliver($operation);
        $this->assertSame('blocked', $operation->fresh()->status);
        $this->assertSame('deployment_gate', $operation->fresh()->failure_code);
        $this->assertDatabaseCount('builds', 1);
        Queue::assertNothingPushed();
        $environment->update(['deployment_locked_at' => null, 'deployment_locked_by' => null]);
        $failingQueue = \Mockery::mock(DeploymentRequest::class);
        $failingQueue->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('sensitive-queue-error'));
        (new ApplicationConfigurationDelivery($builds, $failingQueue))->deliver($operation);
        $this->assertSame('delivery_failed', $operation->fresh()->status);
        $this->assertSame('queue_delivery_failed', $operation->fresh()->failure_code);
        $delivery = app(ApplicationConfigurationDelivery::class);
        $delivery->deliver($operation);
        $this->assertSame('delivery_failed', $operation->fresh()->status);
        Queue::assertNothingPushed();
        $this->artisan('buildpusher:configuration:process')->expectsOutput('Inspected 0 configuration operations; 0 processing errors.')->assertSuccessful();
        // Simulate a worker that reserved delivery and died before enqueueing.
        $operation->update(['status' => 'delivering', 'available_at' => now()->addMinutes(5)]);
        $attempts = $operation->fresh()->attempts;
        $delivery->deliver($operation);
        $this->assertSame($attempts, $operation->fresh()->attempts);
        Queue::assertNothingPushed();
        $operation->update(['available_at' => now()->subSecond()]);
        $delivery->deliver($operation);
        $delivery->deliver($operation);
        $this->assertSame('delivered', $operation->fresh()->status);
        $this->assertDatabaseCount('builds', 1);
        Queue::assertPushed(PublishRepositoryJob::class, 1);
        $results = app(ApplicationConfigurationResults::class);
        $this->assertSame('deploying', $results->refresh($application)->status);
        $this->assertNull($operation->fresh()->completed_at);
        $build->update(['status' => Build::STATUS_RUNNING]);
        $runner = \Mockery::mock(Runner::class);
        // No Runner expectations: any attempt to start another remote process fails.
        (new PublishRepositoryJob($build))->handle($runner);
        (new PublishRepositoryJob($build))->handle($runner);
        $this->assertSame(Build::STATUS_RUNNING, $build->fresh()->status);
        $build->update(['status' => Build::STATUS_FAILED, 'finished_at' => now(), 'failure_message' => 'sensitive-remote-error']);
        $this->artisan('buildpusher:configuration:process', ['--limit' => 10])
            ->expectsOutput('Inspected 1 configuration operations; 0 processing errors.')->assertSuccessful();
        $this->assertSame('remote_failed', $application->fresh()->status);
        $this->assertSame('remote_failed', $duplicateApplication->fresh()->status);
        $this->assertSame('failed', $operation->fresh()->status);
        $this->assertSame('deployment_failed', $operation->fresh()->failure_code);
        $this->assertNotNull($operation->fresh()->completed_at);
        $delivery->deliver($operation);
        $this->assertDatabaseCount('builds', 1);
        Queue::assertPushed(PublishRepositoryJob::class, 1);
        $this->artisan('buildpusher:configuration:process')->expectsOutput('Inspected 0 configuration operations; 0 processing errors.')->assertSuccessful();
        $this->artisan('buildpusher:configuration:process', ['--limit' => 0])->assertFailed();
        $bindings = ['placements' => ['site' => $website->id], 'repositories' => ['app' => $repository->id]];
        $reviews = app(ApplicationConfigurationReviews::class);
        $completedReuse = $service->apply($reviews->create($project, $user, $yaml, $bindings), $user);
        $this->assertSame('remote_failed', $completedReuse->status);
        $this->assertSame($operation->id, $completedReuse->relatedOperations()->firstOrFail()->id);
        $this->assertDatabaseCount('configuration_operations', 1);
        $changedYaml = str_replace('reviewed-command', 'new-reviewed-command', $yaml);
        $changedApplication = $service->apply($reviews->create($project, $user, $changedYaml, $bindings), $user);
        $changedOperation = $changedApplication->relatedOperations()->firstOrFail();
        $this->assertNotSame($operation->id, $changedOperation->id);
        $this->assertSame('awaiting_dispatch', $changedApplication->status);
        $this->assertSame('new-reviewed-command', $changedOperation->payload['attributes']['environment_payload']['runtime']['build_command']);
        $this->assertDatabaseCount('configuration_operations', 2);
        $changedBuild = $builds->prepare($changedOperation);
        $changedBuild->update(['status' => Build::STATUS_SUCCEEDED, 'finished_at' => now()]);
        $this->assertSame('succeeded', $results->refresh($changedApplication)->status);
        $successReuse = $service->apply($reviews->create($project, $user, $changedYaml, $bindings), $user);
        $this->assertSame('succeeded', $successReuse->status);
        $this->assertDatabaseCount('configuration_operations', 2);
        $this->assertDatabaseCount('builds', 2);
        Queue::assertPushed(PublishRepositoryJob::class, 1);
        $missingBuildApplication = $service->apply($reviews->create($project, $user, str_replace('reviewed-command', 'another-command', $yaml), $bindings), $user);
        $missingOperation = $missingBuildApplication->relatedOperations()->firstOrFail();
        $missingBuild = $builds->prepare($missingOperation);
        $missingBuild->delete();
        $this->assertNull($builds->prepare($missingOperation));
        $this->assertSame('build_missing', $missingOperation->fresh()->failure_code);
        $this->assertSame('remote_failed', $results->refresh($missingBuildApplication)->status);
        $delivery->deliver($missingOperation);
        $this->assertDatabaseCount('builds', 2);
        Queue::assertPushed(PublishRepositoryJob::class, 1);
    }
}
