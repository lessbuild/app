<?php

namespace Tests\Feature;

use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Core\Models\DeletionStep;
use App\Core\Services\Deletion\DeletionAuthority;
use App\Modules\Deployer\Http\Middleware\EnsureDeployerDeletionFence;
use App\Modules\Deployer\Jobs\ApplyEnvironmentRuntimeStateJob;
use App\Modules\Deployer\Jobs\Repository\PublishRepositoryJob;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\ConfigurationApplication;
use App\Modules\Deployer\Models\ConfigurationOperation;
use App\Modules\Deployer\Models\ConfigurationReview;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\EnvironmentBlueprintRecipe;
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\ProductDeletionReceipt;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\RecipeReport;
use App\Modules\Deployer\Models\ScheduledTaskRun;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\Core\DeployerProductDeletionProvider;
use App\Modules\Deployer\Services\DeployerMutationClaimManager;
use App\Modules\Deployer\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeployerProductDeletionProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_preflight_reports_other_members_and_explicit_retention(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create();
        $workspace = $owner->currentOrganization;
        $workspace->members()->attach($teammate, ['role' => 'developer']);

        $preview = app(DeployerProductDeletionProvider::class)->inspect(new ProductDeletionTarget(
            'deployer', 'workspace', (string) $workspace->getKey(), (string) $owner->getKey(), 'core-workspace', 'core-actor',
        ));

        $this->assertContains('deployer_workspace_has_other_members', $preview->blockers);
        $this->assertContains('Managed servers and external services remain running and are not contacted or changed.', $preview->retained);
        $this->assertContains('Settled subscriptions and minimal anonymous audit markers are retained; no billing provider is contacted.', $preview->retained);
    }

    public function test_missing_native_workspace_is_a_blocker_without_a_matching_receipt(): void
    {
        $preview = app(DeployerProductDeletionProvider::class)->inspect(new ProductDeletionTarget(
            'deployer', 'workspace', '987654321', '123456789', 'core-workspace', 'core-actor',
        ));

        $this->assertContains('deployer_workspace_missing', $preview->blockers);
    }

    public function test_account_preflight_blocks_user_attributed_resources_in_another_workspace(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $foreignWorkspace = $otherOwner->currentOrganization;
        $provider = $owner->providers()->create([
            'name' => 'Foreign provider', 'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret', 'description' => 'Must not be cascaded from account deletion',
        ]);
        $provider->forceFill(['organization_id' => $foreignWorkspace->getKey()])->save();

        $preview = app(DeployerProductDeletionProvider::class)->inspect(new ProductDeletionTarget(
            'deployer', 'account', (string) $owner->getKey(), (string) $owner->getKey(), 'core-account', 'core-actor',
            [(string) $owner->currentOrganization->getKey()],
        ));

        $this->assertContains('deployer_account_has_foreign_owned_records', $preview->blockers);
        $this->assertTrue($provider->fresh()->exists);
    }

    public function test_account_preflight_blocks_foreign_workspace_blueprint_recipe_attribution(): void
    {
        $actor = User::factory()->create();
        $foreignOwner = User::factory()->create();
        $foreignWorkspace = $foreignOwner->currentOrganization;
        $project = Project::query()->create([
            'organization_id' => $foreignWorkspace->getKey(), 'name' => 'Retained project',
            'slug' => 'retained-'.uniqid(), 'created_by' => $foreignOwner->getKey(),
        ]);
        $environment = Environment::query()->create([
            'project_id' => $project->getKey(), 'name' => 'Staging', 'slug' => 'staging', 'type' => 'staging',
        ]);
        EnvironmentBlueprintRecipe::query()->create([
            'step_id' => '01J8Y7Z9AABBCCDDEEFFGGHHII', 'actor_source_id' => $actor->getKey(),
            'workspace_source_id' => $foreignWorkspace->getKey(), 'canonical_project_id' => 'core-project',
            'canonical_environment_id' => 'core-environment', 'environment_key' => 'staging',
            'environment_id' => $environment->getKey(), 'source_recipe_id' => 987654321,
            'source_user_id' => $actor->getKey(), 'source_organization_id' => $foreignWorkspace->getKey(),
            'source_name' => 'Prepared recipe', 'source_is_published' => false, 'position' => 0,
            'script_snapshot' => 'echo retained', 'script_fingerprint' => str_repeat('a', 64),
            'binding_fingerprint' => str_repeat('b', 64),
        ]);

        $preview = app(DeployerProductDeletionProvider::class)->inspect(new ProductDeletionTarget(
            'deployer', 'account', (string) $actor->getKey(), (string) $actor->getKey(), 'core-account', 'core-actor',
            [(string) $actor->currentOrganization->getKey()],
        ));

        $this->assertContains('deployer_account_has_foreign_owned_records', $preview->blockers);
        $this->assertDatabaseCount('environment_blueprint_recipes', 1, 'deployer');
    }

    public function test_account_preflight_blocks_foreign_workspace_snapshot_attributed_to_personal_recipe_owner(): void
    {
        $deletingActor = User::factory()->create();
        $workspaceOwner = User::factory()->create();
        $foreignWorkspace = $workspaceOwner->currentOrganization;
        $project = Project::query()->create([
            'organization_id' => $foreignWorkspace->getKey(), 'name' => 'Retained project',
            'slug' => 'retained-personal-'.uniqid(), 'created_by' => $workspaceOwner->getKey(),
        ]);
        $environment = Environment::query()->create([
            'project_id' => $project->getKey(), 'name' => 'Staging', 'slug' => 'staging', 'type' => 'staging',
        ]);
        EnvironmentBlueprintRecipe::query()->create([
            'step_id' => '01J8Y7Z9AABBCCDDEEFFGGHHIJ', 'actor_source_id' => $workspaceOwner->getKey(),
            'workspace_source_id' => $foreignWorkspace->getKey(), 'canonical_project_id' => 'core-project',
            'canonical_environment_id' => 'core-environment', 'environment_key' => 'staging',
            'environment_id' => $environment->getKey(), 'source_recipe_id' => 987654322,
            'source_user_id' => $deletingActor->getKey(), 'source_organization_id' => null,
            'source_name' => 'Personal recipe snapshot', 'source_is_published' => false, 'position' => 0,
            'script_snapshot' => 'echo retained', 'script_fingerprint' => str_repeat('c', 64),
            'binding_fingerprint' => str_repeat('d', 64),
        ]);

        $preview = app(DeployerProductDeletionProvider::class)->inspect(new ProductDeletionTarget(
            'deployer', 'account', (string) $deletingActor->getKey(), (string) $deletingActor->getKey(), 'core-account', 'core-actor',
            [(string) $deletingActor->currentOrganization->getKey()],
        ));

        $this->assertNotSame((string) $deletingActor->getKey(), (string) $workspaceOwner->getKey());
        $this->assertContains('deployer_account_has_foreign_owned_records', $preview->blockers);
        $this->assertDatabaseCount('environment_blueprint_recipes', 1, 'deployer');
    }

    public function test_workspace_preflight_blocks_unassigned_user_owned_resources(): void
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'Unassigned provider', 'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret', 'description' => 'Unassigned local resource',
        ]);
        $provider->forceFill(['organization_id' => null])->save();
        $workspace = $owner->currentOrganization;

        $preview = app(DeployerProductDeletionProvider::class)->inspect(new ProductDeletionTarget(
            'deployer', 'workspace', (string) $workspace->getKey(), (string) $owner->getKey(), 'core-workspace', 'core-actor',
        ));

        $this->assertContains('deployer_actor_has_unassigned_records', $preview->blockers);
        $this->assertTrue($provider->fresh()->exists);
    }

    public function test_retry_does_not_report_stale_ready_when_workspace_owner_has_changed(): void
    {
        $owner = User::factory()->create();
        $replacement = User::factory()->create();
        $workspace = $owner->currentOrganization;
        $this->mock(DeletionAuthority::class)->shouldReceive('assertAttempt')->atLeast()->once()->andReturn(new DeletionStep);
        $attempt = new ProductDeletionAttempt(
            'request-deletion', 'step-workspace',
            new ProductDeletionTarget('deployer', 'workspace', (string) $workspace->getKey(), (string) $owner->getKey(), 'core-workspace', 'core-actor'),
            str_repeat('c', 64), 1, 'lease-token', 'prepare',
        );
        $provider = app(DeployerProductDeletionProvider::class);

        $this->assertSame('ready', $provider->prepare($attempt)->status);
        $workspace->forceFill(['owner_id' => $replacement->getKey()])->save();

        $retry = $provider->prepare($attempt);

        $this->assertSame('blocked', $retry->status);
        $this->assertSame('deployer_workspace_not_owned_by_actor', $retry->reasonCode);
        $this->assertSame('ready', ProductDeletionReceipt::query()->where('step_id', $attempt->stepId)->where('phase', 'prepare')->value('status'));
    }

    public function test_admitted_mutation_claim_keeps_prepare_waiting_until_handler_returns(): void
    {
        $owner = User::factory()->create();
        $workspace = $owner->currentOrganization;
        $request = Request::create('/organization/security-policy', 'PATCH');
        $request->setUserResolver(static fn (): User => $owner);
        $route = new Route('PATCH', '/organization/security-policy', []);
        $route->setParameter('organization', $workspace);
        $request->setRouteResolver(static fn (): Route => $route);
        $target = new ProductDeletionTarget(
            'deployer', 'workspace', (string) $workspace->getKey(), (string) $owner->getKey(), 'core-workspace', 'core-actor',
        );

        $response = app(EnsureDeployerDeletionFence::class)->handle($request, function () use ($workspace, $target): Response {
            ProductDeletionFence::query()->create([
                'kind' => 'workspace', 'source_id' => (string) $workspace->getKey(), 'request_id' => 'race-request',
                'payload_hash' => str_repeat('d', 64), 'generation' => 1, 'state' => 'prepared',
            ]);
            $preview = app(DeployerProductDeletionProvider::class)->inspect($target);
            $this->assertContains('deployer_activity_claims_open', $preview->blockers);

            return response('mutation committed');
        });

        $this->assertSame('mutation committed', $response->getContent());
        $this->assertNotContains('deployer_activity_claims_open', app(DeployerProductDeletionProvider::class)->inspect($target)->blockers);
    }

    public function test_rejected_validation_response_releases_mutation_claim(): void
    {
        $owner = User::factory()->create();
        $workspace = $owner->currentOrganization;
        $request = Request::create('/organization/security-policy', 'PATCH');
        $request->setUserResolver(static fn (): User => $owner);
        $route = new Route('PATCH', '/organization/security-policy', []);
        $route->setParameter('organization', $workspace);
        $request->setRouteResolver(static fn (): Route => $route);

        $response = app(EnsureDeployerDeletionFence::class)->handle($request, fn () => response('invalid', 422));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse(DB::connection('deployer')->table('product_deletion_activity_claims')
            ->where('workspace_source_id', (string) $workspace->getKey())->where('status', 'claimed')->exists());
    }

    public function test_validation_exception_releases_mutation_claim_before_propagating(): void
    {
        $owner = User::factory()->create();
        $workspace = $owner->currentOrganization;
        $request = Request::create('/organization/security-policy', 'PATCH');
        $request->setUserResolver(static fn (): User => $owner);
        $route = new Route('PATCH', '/organization/security-policy', []);
        $route->setParameter('organization', $workspace);
        $request->setRouteResolver(static fn (): Route => $route);

        try {
            app(EnsureDeployerDeletionFence::class)->handle($request, static function (): never {
                throw ValidationException::withMessages(['name' => 'Invalid']);
            });
            $this->fail('The validation exception should propagate to Laravel.');
        } catch (ValidationException) {
            $this->assertFalse(DB::connection('deployer')->table('product_deletion_activity_claims')
                ->where('workspace_source_id', (string) $workspace->getKey())->where('status', 'claimed')->exists());
        }
    }

    public function test_personal_recipe_claim_is_actor_only_and_source_cycles_terminate(): void
    {
        $owner = User::factory()->create();
        $recipe = $owner->recipes()->create(['name' => 'Personal', 'description' => null, 'script' => 'echo safe']);
        $recipe->forceFill(['organization_id' => null, 'source_recipe_id' => $recipe->getKey()])->save();
        $request = Request::create('/gallery/'.$recipe->getKey().'/favorite', 'POST');
        $request->setUserResolver(static fn (): User => $owner);
        $route = new Route('POST', '/gallery/{recipe}/favorite', []);
        $route->setParameter('recipe', $recipe);
        $request->setRouteResolver(static fn (): Route => $route);

        $claimGroupId = app(DeployerMutationClaimManager::class)->claimRequest($request);

        $this->assertNotNull($claimGroupId);
        $this->assertDatabaseHas('product_deletion_activity_claims', [
            'claim_group_id' => $claimGroupId,
            'actor_source_id' => (string) $owner->getKey(),
            'workspace_source_id' => null,
        ], 'deployer');
    }

    public function test_configuration_retry_and_scheduled_task_run_resolve_parent_workspace_scope(): void
    {
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id, 'name' => 'Application', 'slug' => 'application',
        ]);
        $environment = $project->environments()->create([
            'name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'branch' => 'main',
        ]);
        $review = ConfigurationReview::query()->create([
            'project_id' => $project->id, 'requested_by' => $owner->id,
            'document' => '{}', 'bindings' => '[]', 'summary' => [], 'expires_at' => now()->addHour(),
        ]);
        $application = ConfigurationApplication::query()->create([
            'configuration_review_id' => $review->id, 'status' => 'applied',
        ]);
        $operation = ConfigurationOperation::query()->create([
            'configuration_application_id' => $application->id, 'environment_slug' => $environment->slug,
            'environment_id' => $environment->id, 'kind' => 'deploy', 'status' => 'failed', 'payload' => [],
        ]);
        $run = ScheduledTaskRun::query()->create(['scheduled_task_id' => $environment->scheduledTasks()->create([
            'created_by' => $owner->id, 'name' => 'Maintenance', 'command' => 'artisan inspire', 'cron_expression' => '0 * * * *',
        ])->id, 'status' => 'queued']);
        $manager = app(DeployerMutationClaimManager::class);

        $configRequest = Request::create('/projects/'.$project->id.'/configuration/'.$review->id.'/operations/'.$operation->id.'/retry', 'POST');
        $configRequest->setUserResolver(static fn (): User => $owner);
        $configRoute = new Route('POST', '/projects/{project}/configuration/{review}/operations/{operation}/retry', []);
        $configRoute->setParameter('project', $project);
        $configRoute->setParameter('review', $review);
        $configRoute->setParameter('operation', (string) $operation->id);
        $configRequest->setRouteResolver(static fn (): Route => $configRoute);
        $configClaim = $manager->claimRequest($configRequest);

        $taskRequest = Request::create('/scheduled-task-runs/'.$run->id, 'POST');
        $taskRequest->setUserResolver(static fn (): User => $owner);
        $taskRoute = new Route('POST', '/scheduled-task-runs/{run}', []);
        $taskRoute->setParameter('run', $run);
        $taskRequest->setRouteResolver(static fn (): Route => $taskRoute);
        $taskClaim = $manager->claimRequest($taskRequest);

        $this->assertNotNull($configClaim);
        $this->assertNotNull($taskClaim);
        $this->assertSame(2, DB::connection('deployer')->table('product_deletion_activity_claims')
            ->where('workspace_source_id', (string) $owner->currentOrganization->getKey())
            ->where('status', 'claimed')->count());
    }

    public function test_recipe_report_mutations_claim_the_recipe_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = $owner->currentOrganization;
        $recipe = $owner->recipes()->create(['name' => 'Workspace recipe', 'script' => 'echo safe']);
        $recipe->forceFill(['organization_id' => $workspace->getKey()])->save();
        $report = new RecipeReport;
        $report->forceFill(['recipe_id' => $recipe->getKey(), 'user_id' => $owner->getKey(), 'reason' => 'broken', 'details' => 'Review requested'])->save();
        $request = Request::create('/recipe-reports/'.$report->getKey(), 'PATCH');
        $request->setUserResolver(static fn (): User => $owner);
        $route = new Route('PATCH', '/recipe-reports/{report}', []);
        $route->setParameter('report', $report);
        $request->setRouteResolver(static fn (): Route => $route);

        $claim = app(DeployerMutationClaimManager::class)->claimRequest($request);

        $this->assertDatabaseHas('product_deletion_activity_claims', [
            'claim_group_id' => $claim,
            'workspace_source_id' => (string) $workspace->getKey(),
        ], 'deployer');
    }

    public function test_untracked_remote_job_claim_blocks_deletion_without_time_based_expiry(): void
    {
        $owner = User::factory()->create();
        $workspace = $owner->currentOrganization;
        $target = new ProductDeletionTarget(
            'deployer', 'workspace', (string) $workspace->getKey(), (string) $owner->getKey(), 'core-workspace', 'core-actor',
        );
        $manager = app(DeployerMutationClaimManager::class);
        $claimGroupId = $manager->claimWorkspace($workspace->getKey(), 'environment.apply_runtime_state');

        $this->assertNotNull($claimGroupId);
        $this->assertContains('deployer_activity_claims_open', app(DeployerProductDeletionProvider::class)->inspect($target)->blockers);
        Carbon::setTestNow(now()->addDays(30));
        try {
            $this->assertContains('deployer_activity_claims_open', app(DeployerProductDeletionProvider::class)->inspect($target)->blockers);
        } finally {
            Carbon::setTestNow();
        }

        $manager->complete($claimGroupId);
        $this->assertNotContains('deployer_activity_claims_open', app(DeployerProductDeletionProvider::class)->inspect($target)->blockers);
    }

    public function test_queued_runtime_job_started_after_fence_or_target_removal_never_contacts_remote_server(): void
    {
        $owner = User::factory()->create();
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id, 'name' => 'Application', 'slug' => 'application',
        ]);
        $environment = $project->environments()->create([
            'name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'branch' => 'main',
        ]);
        $queuedBeforeFence = new ApplyEnvironmentRuntimeStateJob($environment->id, true);
        ProductDeletionFence::query()->create([
            'kind' => 'workspace', 'source_id' => (string) $owner->currentOrganization->getKey(),
            'request_id' => 'request-runtime', 'payload_hash' => str_repeat('e', 64), 'generation' => 1, 'state' => 'prepared',
        ]);
        $runner = \Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');

        $queuedBeforeFence->handle($runner);
        (new ApplyEnvironmentRuntimeStateJob(999999, true))->handle($runner);

        $this->assertFalse(DB::connection('deployer')->table('product_deletion_activity_claims')
            ->where('workspace_source_id', (string) $owner->currentOrganization->getKey())
            ->where('operation', 'environment.apply_runtime_state')
            ->where('status', 'claimed')->exists());
    }

    public function test_persisted_workspace_fence_rejects_new_authenticated_mutation(): void
    {
        $owner = User::factory()->create();
        $workspace = $owner->currentOrganization;
        ProductDeletionFence::query()->create([
            'kind' => 'workspace', 'source_id' => (string) $workspace->getKey(),
            'request_id' => 'request-1', 'payload_hash' => str_repeat('a', 64), 'generation' => 1, 'state' => 'prepared',
        ]);
        $request = Request::create('/organization/security-policy', 'PATCH');
        $request->setUserResolver(fn (): User => $owner);

        $this->expectException(HttpException::class);
        app(EnsureDeployerDeletionFence::class)->handle($request, fn () => response('allowed'));
    }

    public function test_queued_build_claim_is_canceled_when_its_workspace_is_fenced(): void
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub', 'provider' => Provider::TYPE_GITHUB, 'token' => 'source-secret', 'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create(['name' => 'Production', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'Application', 'description' => 'Website',
            'environment' => '', 'url' => 'app.example.com', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'Application repository',
            'url' => 'github.com/example/application.git', 'branch' => 'main', 'description' => 'Application source',
        ]);
        $build = $repository->builds()->create(['status' => Build::STATUS_QUEUED]);
        $workspace = $owner->currentOrganization;
        ProductDeletionFence::query()->create([
            'kind' => 'workspace', 'source_id' => (string) $workspace->getKey(),
            'request_id' => 'request-build', 'payload_hash' => str_repeat('b', 64), 'generation' => 1, 'state' => 'prepared',
        ]);
        $deletionProvider = app(DeployerProductDeletionProvider::class);
        $target = new ProductDeletionTarget('deployer', 'workspace', (string) $workspace->getKey(), (string) $owner->getKey(), 'core-workspace', 'core-actor');

        $this->assertContains('deployer_active_operations', $deletionProvider->inspect($target)->blockers);
        (new PublishRepositoryJob($build))->handle(new Runner);

        $this->assertSame(Build::STATUS_CANCELED, $build->fresh()->status);
        $this->assertNotContains('deployer_active_operations', $deletionProvider->inspect($target)->blockers);
    }
}
