<?php

namespace Tests\Feature;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\ConfigurationOperation;
use App\Models\PreviewDeployment;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ApplicationConfigurationBuilds;
use App\Services\ApplicationConfigurationCancellation;
use App\Services\ApplicationConfigurationDelivery;
use App\Services\ApplicationConfigurationExecution;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationResults;
use App\Services\ApplicationConfigurationRetries;
use App\Services\ApplicationConfigurationReviews;
use App\Services\Runner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationConfigurationRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_deployment_retry_is_explicit_idempotent_private_and_shared_by_receipts(): void
    {
        [$user, $project, $repository, $application, $operation, $build, $yaml, $bindings] = $this->fixture();
        $shared = app(ApplicationConfigurationReconciler::class)->apply(app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, $bindings), $user);
        $retry = app(ApplicationConfigurationRetries::class)->retry($operation, $user);
        $this->assertSame($retry->id, app(ApplicationConfigurationRetries::class)->retry($operation, $user)->id);
        $this->assertSame($operation->id, $retry->retry_of_operation_id);
        $this->assertSame(1, $retry->retry_sequence);
        $this->assertSame($operation->payload['attributes']['environment_payload'], $retry->payload['attributes']['environment_payload']);
        $this->assertStringNotContainsString('private-build-command', $retry->getRawOriginal('payload'));
        $this->assertArrayNotHasKey('payload', $retry->toArray());
        $this->assertSame(Build::STATUS_FAILED, $build->fresh()->status);
        $this->assertSame('failed', $operation->fresh()->status);
        $this->assertSame('awaiting_dispatch', $application->fresh()->status);
        $this->assertSame('awaiting_dispatch', $shared->fresh()->status);
        $this->assertDatabaseCount('builds', 2);
        $this->assertDatabaseCount('configuration_operations', 2);
        Queue::assertNothingPushed();
        $delivery = app(ApplicationConfigurationDelivery::class);
        $delivery->deliver($retry);
        $delivery->deliver($retry);
        Queue::assertPushed(PublishRepositoryJob::class, 1);
        $retry->build->update(['status' => Build::STATUS_SUCCEEDED, 'finished_at' => now()]);
        $this->assertSame('succeeded', app(ApplicationConfigurationResults::class)->refresh($application)->status);
        $this->assertSame('succeeded', app(ApplicationConfigurationResults::class)->refresh($shared)->status);
        $this->assertSame($retry->id, app(ApplicationConfigurationRetries::class)->retry($operation, $user)->id);
        $newReview = app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, $bindings);
        $reused = app(ApplicationConfigurationReconciler::class)->apply($newReview, $user);
        $this->assertSame($retry->id, $reused->relatedOperations()->firstOrFail()->id);
        $this->assertSame('succeeded', $reused->status);
        $this->assertDatabaseCount('builds', 2);
    }

    public function test_each_further_remote_retry_requires_its_own_failed_operation_identity_and_fresh_approval(): void
    {
        [$user, $project, , , $original, , $yaml, $bindings] = $this->fixture();
        $original->environment->update(['requires_deployment_approval' => true]);
        $service = app(ApplicationConfigurationRetries::class);
        $retry = $service->retry($original, $user);
        $this->assertSame(Build::STATUS_AWAITING_APPROVAL, $retry->build->status);
        $this->assertNull($retry->build->approved_at);
        app(ApplicationConfigurationDelivery::class)->deliver($retry);
        Queue::assertNothingPushed();
        $retry->build->update(['status' => Build::STATUS_FAILED, 'approved_at' => now(), 'approved_by' => $user->id, 'finished_at' => now()]);
        $this->assertSame($retry->id, $service->retry($original, $user)->id);
        $second = $service->retry($retry, $user);
        $this->assertNotSame($retry->id, $second->id);
        $this->assertSame(2, $second->retry_sequence);
        $this->assertSame(Build::STATUS_AWAITING_APPROVAL, $second->build->status);
        $this->assertNull($second->build->approved_at);
        $this->assertNull($second->build->approved_by);
        $this->assertDatabaseCount('builds', 3);
        $second->build->update(['status' => Build::STATUS_SUCCEEDED, 'finished_at' => now()]);
        $reused = app(ApplicationConfigurationReconciler::class)->apply(app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, $bindings), $user);
        $this->assertSame($second->id, $reused->relatedOperations()->firstOrFail()->id);
        $this->assertDatabaseCount('builds', 3);
    }

    #[DataProvider('blockedRetries')]
    public function test_retry_rechecks_current_state_and_rolls_back_when_blocked(string $change): void
    {
        [$user, $project, $repository, $application, $operation, $build] = $this->fixture();
        match ($change) {
            'repository' => $repository->update(['branch' => 'changed']),
            'runtime' => $operation->environment->update(['build_command' => 'changed']),
            'gate' => $operation->environment->update(['deployment_locked_at' => now(), 'deployment_locked_by' => $user->id]),
            'active' => $repository->builds()->create(['status' => Build::STATUS_QUEUED]),
            'target' => $repository->website->update(['provisioning_status' => Website::STATUS_FAILED]),
            'succeeded' => $build->update(['status' => Build::STATUS_SUCCEEDED]),
            'missing' => $build->delete(),
        };
        try {
            app(ApplicationConfigurationRetries::class)->retry($operation, $user);
            $this->fail('Unsafe retry was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operation', $exception->errors());
        }
        $this->assertDatabaseCount('configuration_operations', 1);
        $this->assertDatabaseCount('builds', $change === 'active' ? 2 : ($change === 'missing' ? 0 : 1));
        Queue::assertNothingPushed();
    }

    public static function blockedRetries(): array
    {
        return array_map(fn ($value) => [$value], ['repository', 'runtime', 'gate', 'active', 'target', 'succeeded', 'missing']);
    }

    public function test_retry_rechecks_permission_even_for_an_idempotent_replay(): void
    {
        [$user, , , , $operation] = $this->fixture();
        app(ApplicationConfigurationRetries::class)->retry($operation, $user);
        $user->update(['current_organization_id' => User::factory()->create()->current_organization_id]);
        $this->expectException(AuthorizationException::class);
        app(ApplicationConfigurationRetries::class)->retry($operation, $user);
    }

    public function test_newer_intent_prevents_retrying_an_old_failure(): void
    {
        [$user, $project, , , $operation, , $yaml, $bindings] = $this->fixture();
        $new = app(ApplicationConfigurationReconciler::class)->apply(app(ApplicationConfigurationReviews::class)->create($project, $user, str_replace('private-build-command', 'new-command', $yaml), $bindings), $user);
        $this->expectException(ValidationException::class);
        app(ApplicationConfigurationRetries::class)->retry($operation, $user);
    }

    public function test_api_retry_is_scoped_and_returns_replayed_receipt_without_secrets(): void
    {
        config(['billing.enforce_entitlements' => false]);
        [$user, $project, , $application, $operation] = $this->fixture();
        $url = '/api/v1/projects/'.$project->id.'/configuration/applications/'.$application->id.'/operations/'.$operation->id.'/retry';
        $this->postJson($url)->assertUnauthorized();
        Sanctum::actingAs($user, ['read']);
        $this->postJson($url)->assertForbidden();
        Sanctum::actingAs($user, ['manage']);
        $this->postJson($url, ['payload' => 'replacement'])->assertUnprocessable();
        $response = $this->postJson($url)->assertOk()->assertJsonPath('data.status', 'awaiting_dispatch');
        $retryId = $response->json('retry_operation_id');
        $this->assertStringNotContainsString('private-build-command', $response->getContent());
        $this->assertStringNotContainsString('private-remote-error', $response->getContent());
        $this->postJson($url)->assertOk()->assertJsonPath('retry_operation_id', $retryId);
        $this->assertDatabaseCount('builds', 2);
        ConfigurationOperation::findOrFail($retryId)->build->update(['status' => Build::STATUS_SUCCEEDED, 'finished_at' => now()]);
        $this->getJson('/api/v1/projects/'.$project->id.'/configuration/applications/'.$application->id)->assertOk()->assertJsonPath('data.status', 'succeeded');
        Sanctum::actingAs(User::factory()->create(), ['manage']);
        $this->postJson($url)->assertForbidden();
    }

    public function test_repository_provider_changes_create_a_new_intent_while_webhook_bookkeeping_does_not(): void
    {
        [$user, $project, $repository, , $operation, , $yaml, $bindings] = $this->fixture();
        $repository->update(['setup_stage' => 4, 'webhook_pending' => true, 'webhook_last_received_at' => now()]);
        $service = app(ApplicationConfigurationReconciler::class);
        $reviews = app(ApplicationConfigurationReviews::class);
        $unchanged = $service->apply($reviews->create($project, $user, $yaml, $bindings), $user);
        $this->assertSame($operation->id, $unchanged->relatedOperations()->firstOrFail()->id);
        $provider = $user->providers()->create(['name' => 'Other GitHub', 'provider' => 'github', 'token' => 'new-secret', 'description' => 'Git']);
        $repository->update(['provider_id' => $provider->id]);
        $changed = $service->apply($reviews->create($project, $user, $yaml, $bindings), $user);
        $replacement = $changed->relatedOperations()->firstOrFail();
        $this->assertNotSame($operation->id, $replacement->id);
        $this->assertNotSame($operation->intent_digest, $replacement->intent_digest);
        $build = app(ApplicationConfigurationBuilds::class)->prepare($replacement);
        $this->assertNotNull($build);
        $this->assertDatabaseCount('configuration_operations', 2);
    }

    public function test_equivalent_reencrypted_repository_commands_preserve_intent_identity(): void
    {
        [$user, $project, $repository, , , , $yaml, $bindings] = $this->fixture();
        $repository->update(['build_commands' => 'private-repository-command']);
        $service = app(ApplicationConfigurationReconciler::class);
        $reviews = app(ApplicationConfigurationReviews::class);
        $first = $service->apply($reviews->create($project, $user, $yaml, $bindings), $user)->relatedOperations()->firstOrFail();
        $ciphertext = $repository->getRawOriginal('build_commands');
        $repository->update(['build_commands' => 'private-repository-command']);
        $this->assertNotSame($ciphertext, $repository->getRawOriginal('build_commands'));
        $second = $service->apply($reviews->create($project, $user, $yaml, $bindings), $user)->relatedOperations()->firstOrFail();
        $this->assertSame($first->id, $second->id);
        $this->assertNotNull(app(ApplicationConfigurationBuilds::class)->prepare($second));
        $this->assertDatabaseCount('configuration_operations', 2);
    }

    #[DataProvider('changesAfterQueueing')]
    public function test_remote_start_rechecks_configuration_gates_after_queue_delivery(string $change): void
    {
        [$user, , $repository, , $original] = $this->fixture();
        $retry = app(ApplicationConfigurationRetries::class)->retry($original, $user);
        app(ApplicationConfigurationDelivery::class)->deliver($retry);
        $build = $retry->build;
        match ($change) {
            'repository' => $repository->update(['build_commands' => 'unreviewed-command']),
            'gate' => $retry->environment->update(['deployment_locked_at' => now(), 'deployment_locked_by' => $user->id]),
            'approval' => $retry->environment->update(['requires_deployment_approval' => true]),
            'permission' => $this->revokeManagement($user),
            'target' => $repository->website->update(['provisioning_status' => Website::STATUS_FAILED]),
            'entitlement' => config(['billing.enforce_entitlements' => true, 'billing.plans.free.entitlements' => []]),
            'website_environment' => $repository->website->update(['environment' => 'PRIVATE=unreviewed-secret']),
            'preview' => PreviewDeployment::create(['project_id' => $retry->environment->project_id, 'source_repository_id' => $repository->id, 'environment_id' => $retry->environment_id, 'website_id' => $repository->website_id, 'repository_id' => $repository->id, 'pull_request_number' => 8, 'source_branch' => 'feature', 'revision' => str_repeat('a', 40), 'url' => 'preview.test', 'last_activity_at' => now(), 'status' => PreviewDeployment::STATUS_READY]),
        };
        $runner = \Mockery::mock(Runner::class);
        (new PublishRepositoryJob($build))->handle($runner);
        $this->assertSame($change === 'approval' ? Build::STATUS_AWAITING_APPROVAL : Build::STATUS_QUEUED, $build->fresh()->status);
        $this->assertSame($change === 'approval' ? 'awaiting_approval' : 'blocked', $retry->fresh()->status);
        $this->assertNull($build->fresh()->remote_process_id);
        $this->assertDatabaseCount('builds', 2);
    }

    public static function changesAfterQueueing(): array
    {
        return array_map(fn ($value) => [$value], ['repository', 'gate', 'approval', 'permission', 'target', 'entitlement', 'preview', 'website_environment']);
    }

    #[DataProvider('deletedConfigurationOrigins')]
    public function test_a_queued_configuration_build_cannot_start_after_its_authorization_records_are_deleted(string $record): void
    {
        [$user, $project, , $application, $operation] = $this->fixture(false);
        $build = app(ApplicationConfigurationBuilds::class)->prepare($operation);
        app(ApplicationConfigurationDelivery::class)->deliver($operation);
        $this->assertSame($operation->id, $build->environment_payload['configuration_operation_id']);
        $this->assertStringNotContainsString('configuration_operation_id', $build->getRawOriginal('environment_payload'));
        match ($record) {
            'operation' => $operation->delete(),
            'review' => $application->review->delete(),
            'project' => $project->delete(),
        };
        $this->assertDatabaseMissing('configuration_operations', ['id' => $operation->id]);
        (new PublishRepositoryJob($build))->handle(\Mockery::mock(Runner::class));
        (new PublishRepositoryJob($build))->handle(\Mockery::mock(Runner::class));
        $this->assertSame(Build::STATUS_CANCELED, $build->fresh()->status);
        $this->assertNull($build->fresh()->remote_process_id);
        $this->assertNotNull($build->fresh()->finished_at);
    }

    public static function deletedConfigurationOrigins(): array
    {
        return [['operation'], ['review'], ['project']];
    }

    public function test_a_configuration_build_origin_must_match_its_linked_operation(): void
    {
        [, , , , $operation] = $this->fixture(false);
        $build = app(ApplicationConfigurationBuilds::class)->prepare($operation);
        $payload = $build->environment_payload;
        $payload['configuration_operation_id'] = $operation->id + 1;
        $build->update(['environment_payload' => $payload]);
        (new PublishRepositoryJob($build))->handle(\Mockery::mock(Runner::class));
        $this->assertSame(Build::STATUS_CANCELED, $build->fresh()->status);
        $this->assertNull($build->fresh()->remote_process_id);
    }

    public function test_deleting_a_requester_preserves_the_receipt_and_blocks_its_queued_build(): void
    {
        [$owner, $project, , $application, $operation] = $this->fixture(false);
        $requester = User::factory()->create(['current_organization_id' => $project->organization_id]);
        $project->organization->members()->attach($requester, ['role' => 'admin']);
        $application->review->update(['requested_by' => $requester->id]);
        $build = app(ApplicationConfigurationBuilds::class)->prepare($operation->fresh());
        $this->assertNotNull($build);
        $requester->delete();
        $this->assertDatabaseHas('configuration_reviews', ['id' => $application->configuration_review_id, 'requested_by' => null]);
        $this->assertDatabaseHas('configuration_operations', ['id' => $operation->id]);
        (new PublishRepositoryJob($build))->handle(\Mockery::mock(Runner::class));
        $this->assertSame(Build::STATUS_QUEUED, $build->fresh()->status);
        $this->assertSame('permission_revoked', $operation->fresh()->failure_code);
        $this->assertSame('blocked', $operation->fresh()->status);
        $this->assertNull($build->fresh()->remote_process_id);
    }

    public function test_remote_start_claims_the_same_configuration_build_once(): void
    {
        [$user, , , , $original] = $this->fixture();
        $retry = app(ApplicationConfigurationRetries::class)->retry($original, $user);
        $service = app(ApplicationConfigurationExecution::class);
        $this->assertTrue($service->claim($retry->build));
        $this->assertFalse($service->claim($retry->build));
        $this->assertSame(Build::STATUS_DEPLOYING, $retry->build->fresh()->status);
        $this->assertDatabaseCount('builds', 2);
    }

    public function test_canceling_a_stale_pending_intent_releases_environment_removal_and_cannot_dispatch(): void
    {
        [$user, $project, $repository, $application, $operation] = $this->fixture(false);
        $repository->update(['branch' => 'changed']);
        $this->assertNull(app(ApplicationConfigurationBuilds::class)->prepare($operation));
        $this->assertSame('blocked', $operation->fresh()->status);
        $service = app(ApplicationConfigurationCancellation::class);
        $canceled = $service->cancel($operation, $user);
        $this->assertSame('canceled', $canceled->status);
        $this->assertSame($canceled->id, $service->cancel($operation, $user)->id);
        app(ApplicationConfigurationDelivery::class)->deliver($operation);
        $this->assertNull(app(ApplicationConfigurationBuilds::class)->prepare($operation));
        $this->assertDatabaseCount('builds', 0);
        Queue::assertNothingPushed();
        $review = app(ApplicationConfigurationReviews::class)->create($project, $user, "version: 2\nremove:\n  environments: [staging]\n", []);
        app(ApplicationConfigurationReconciler::class)->apply($review, $user);
        $this->assertDatabaseCount('environments', 0);
        $this->assertDatabaseCount('websites', 1);
        $this->assertSame('canceled', $operation->fresh()->status);
    }

    public function test_a_canceled_intent_without_a_build_can_be_explicitly_retried_once(): void
    {
        [$user, , , , $operation] = $this->fixture(false);
        app(ApplicationConfigurationCancellation::class)->cancel($operation, $user);
        $retry = app(ApplicationConfigurationRetries::class)->retry($operation, $user);
        $this->assertSame($retry->id, app(ApplicationConfigurationRetries::class)->retry($operation, $user)->id);
        $this->assertSame(Build::STATUS_QUEUED, $retry->build->status);
        $this->assertDatabaseCount('builds', 1);
        $this->assertDatabaseCount('configuration_operations', 2);
    }

    public function test_canceling_a_queued_build_is_idempotent_and_its_delayed_job_cannot_start(): void
    {
        [$user, , , , $operation] = $this->fixture(false);
        $build = app(ApplicationConfigurationBuilds::class)->prepare($operation);
        app(ApplicationConfigurationDelivery::class)->deliver($operation);
        $service = app(ApplicationConfigurationCancellation::class);
        $service->cancel($operation, $user);
        $service->cancel($operation, $user);
        (new PublishRepositoryJob($build))->handle(\Mockery::mock(Runner::class));
        $this->assertSame(Build::STATUS_CANCELED, $build->fresh()->status);
        $this->assertSame('canceled', $operation->fresh()->status);
        $this->assertDatabaseCount('builds', 1);
    }

    public function test_cancel_rechecks_access_and_rejects_running_builds(): void
    {
        [$user, , , , $operation] = $this->fixture(false);
        $build = app(ApplicationConfigurationBuilds::class)->prepare($operation);
        $build->update(['status' => Build::STATUS_RUNNING]);
        try {
            app(ApplicationConfigurationCancellation::class)->cancel($operation, $user);
            $this->fail('Running build cancellation must use deployment controls.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operation', $exception->errors());
        }
        $this->assertSame(Build::STATUS_RUNNING, $build->fresh()->status);
        $this->revokeManagement($user);
        $this->expectException(AuthorizationException::class);
        app(ApplicationConfigurationCancellation::class)->cancel($operation, $user);
    }

    public function test_api_and_web_cancel_pending_operations_without_remote_work(): void
    {
        config(['billing.enforce_entitlements' => false]);
        [$user, $project, , $application, $operation] = $this->fixture(false);
        $api = '/api/v1/projects/'.$project->id.'/configuration/applications/'.$application->id.'/operations/'.$operation->id.'/cancel';
        Sanctum::actingAs($user, ['read']);
        $this->postJson($api)->assertForbidden();
        Sanctum::actingAs($user, ['manage']);
        $this->postJson($api, ['document' => 'replacement'])->assertUnprocessable();
        $this->postJson($api)->assertOk()->assertJsonPath('data.operations.0.status', 'canceled');
        $receipt = route('projects.configuration.review', [$project, $application->review]);
        $this->actingAs($user, 'web')->post(route('projects.configuration.cancel', [$project, $application->review, $operation]))->assertRedirect($receipt);
        $this->assertDatabaseCount('builds', 0);
        Queue::assertNothingPushed();
    }

    public function test_web_receipt_refreshes_results_and_offers_explicit_retry(): void
    {
        [$user, $project, , $application, $operation] = $this->fixture();
        $application->update(['status' => 'deploying']);
        $receipt = route('projects.configuration.review', [$project, $application->review]);
        $this->actingAs($user)->get($receipt)->assertOk()->assertSee('remote_failed')->assertSee('Retry failed deployment')->assertDontSee('private-build-command');
        $retryUrl = route('projects.configuration.retry', [$project, $application->review, $operation]);
        $this->post($retryUrl)->assertRedirect($receipt);
        $this->post($retryUrl)->assertRedirect($receipt);
        $this->get($receipt)->assertOk()->assertSee('Retried by operation');
        $this->assertDatabaseCount('builds', 2);
    }

    private function revokeManagement(User $user): void
    {
        $user->currentOrganization->update(['owner_id' => User::factory()->create()->id]);
        $user->currentOrganization->members()->updateExistingPivot($user->id, ['role' => 'viewer']);
    }

    private function fixture(bool $prepareBuild = true): array
    {
        Queue::fake();
        $user = User::factory()->create();
        $provider = $user->providers()->create(['name' => 'GitHub', 'provider' => 'github', 'token' => 'secret', 'description' => 'Git']);
        $server = $user->servers()->create(['name' => 'Test', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'App', 'url' => 'app.test', 'description' => 'Test', 'environment' => '', 'provisioning_status' => Website::STATUS_ACTIVE]);
        $repository = $user->repositories()->create(['provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'App', 'url' => 'github.com/example/app.git', 'branch' => 'main', 'description' => 'Test']);
        $project = $user->currentOrganization->projects()->create(['name' => 'App', 'slug' => 'app', 'created_by' => $user->id]);
        $yaml = "version: 2\nenvironments:\n  staging:\n    type: staging\n    placement: site\n    runtime:\n      type: php\n      build_command: private-build-command\n    deploy:\n      repository: app\n";
        $bindings = ['placements' => ['site' => $website->id], 'repositories' => ['app' => $repository->id]];
        $review = app(ApplicationConfigurationReviews::class)->create($project, $user, $yaml, $bindings);
        $application = app(ApplicationConfigurationReconciler::class)->apply($review, $user);
        $operation = $application->relatedOperations()->firstOrFail();
        $build = null;
        if ($prepareBuild) {
            $build = app(ApplicationConfigurationBuilds::class)->prepare($operation);
            $build->update(['status' => Build::STATUS_FAILED, 'finished_at' => now(), 'failure_message' => 'private-remote-error']);
            app(ApplicationConfigurationResults::class)->refresh($application);
        }

        return [$user, $project, $repository, $application->fresh(), $operation->fresh(), $build, $yaml, $bindings];
    }
}
