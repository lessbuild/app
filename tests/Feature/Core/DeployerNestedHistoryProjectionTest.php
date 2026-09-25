<?php

namespace Tests\Feature\Core;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Modules\Deployer\Http\Controllers\DashboardController;
use App\Modules\Deployer\Http\Controllers\ProjectController;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\DashboardCreationDialogData;
use App\Modules\Deployer\Services\Entitlements;
use App\Modules\Deployer\Services\PlanLimits;
use App\Modules\Deployer\Services\PublicPlatformStatus;
use App\Modules\Deployer\Services\RepositoryDeploymentInsightsQuery;
use App\Modules\Deployer\Services\RepositoryWebhookDeliveryHistoryExporter;
use App\Modules\Deployer\Services\RepositoryWebhookDeliveryHistoryQuery;
use App\Modules\Deployer\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/** Authored only: run after the authorized plan-completion checkpoint. */
final class DeployerNestedHistoryProjectionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PlatformUser $principal;

    private Workspace $workspace;

    private Project $project;

    private Environment $environment;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('platform:migrate', ['module' => 'core']);
        Queue::fake();
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
        $this->principal = PlatformUser::query()->create([
            'name' => 'Projection actor', 'email' => 'projection@example.test',
            'email_normalized' => 'projection@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $this->workspace = Workspace::query()->create([
            'owner_user_id' => $this->principal->id, 'name' => 'Projection team', 'slug' => 'projection-team', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->id, 'user_id' => $this->principal->id, 'role' => 'owner', 'status' => 'active',
        ]);
        WorkspaceProductAccess::query()->create(['membership_id' => $membership->id, 'product' => 'deployer', 'role' => 'owner', 'status' => 'active']);
        foreach (['user' => [$this->actor->id, 'user', $this->principal->id], 'organization' => [$this->actor->current_organization_id, 'workspace', $this->workspace->id]] as $entity => [$source, $canonicalEntity, $canonical]) {
            LegacyIdentityMap::query()->create([
                'source_product' => 'deployer', 'source_entity' => $entity, 'source_id' => (string) $source,
                'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonical, 'status' => 'reconciled',
            ]);
        }
        $this->project = $this->actor->currentOrganization->projects()->create(['created_by' => $this->actor->id, 'name' => 'Permitted application', 'slug' => 'permitted']);
        $this->environment = $this->project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production']);
        $provider = $this->actor->providers()->create(['name' => 'Git', 'provider' => 'github', 'description' => 'Git', 'token' => 'secret']);
        $server = $this->actor->servers()->create(['name' => 'Allowed server', 'provider_id' => $provider->id]);
        $website = $this->actor->websites()->create(['name' => 'Allowed website', 'description' => 'Site', 'url' => 'projection.example.test', 'server_id' => $server->id]);
        $this->environment->update(['server_id' => $server->id, 'website_id' => $website->id]);
        $this->repository = $this->actor->repositories()->create([
            'name' => 'Permitted repository', 'description' => 'Repository', 'url' => 'https://github.com/example/projection.git',
            'website_id' => $website->id, 'provider_id' => $provider->id, 'branch' => 'main',
        ]);
    }

    public function test_insights_remove_independently_denied_builds_before_counts_and_duration_sampling(): void
    {
        $allowed = $this->build(Build::STATUS_SUCCEEDED, 'allowed-revision', 60);
        $hidden = $this->build(Build::STATUS_FAILED, 'hidden-revision', 900);
        $this->deny('build', $hidden->id);
        $this->assertTrue($this->actor->can('view', $this->repository));
        $metrics = app(RepositoryDeploymentInsightsQuery::class)->metrics($this->repository, $this->actor);
        $this->assertSame(['total' => 1, 'succeeded' => 1, 'failed' => 0, 'success_rate' => 100, 'median_duration_seconds' => 60, 'duration_sample_size' => 1], $metrics);
        $this->assertSame(2, $this->repository->builds()->count());
        $this->assertTrue($this->actor->can('view', $allowed));
        config(['platform.products.deployer.auth_authority' => 'legacy']);
        $this->assertSame(2, app(RepositoryDeploymentInsightsQuery::class)->metrics($this->repository, $this->actor)['total']);
    }

    public function test_webhook_rows_filters_counts_and_export_exclude_denied_or_mismatched_build_payloads(): void
    {
        $allowed = $this->build(Build::STATUS_SUCCEEDED, 'allowed-revision', 60);
        $hidden = $this->build(Build::STATUS_FAILED, 'hidden-revision', 90);
        $this->deny('build', $hidden->id);
        $visible = $this->repository->webhookDeliveries()->create(['delivery_id' => 'allowed-delivery', 'build_id' => $allowed->id, 'status' => 'queued', 'revision' => 'allowed-revision']);
        $this->repository->webhookDeliveries()->create(['delivery_id' => 'hidden-delivery', 'build_id' => $hidden->id, 'status' => 'queued', 'revision' => 'hidden-revision', 'commit_message' => 'hidden-commit']);
        $pending = $this->repository->webhookDeliveries()->create(['delivery_id' => 'pending-delivery', 'status' => 'pending', 'revision' => 'pending-revision']);
        $other = $this->repository->replicate();
        $other->name = 'Other repository';
        $other->save();
        $foreignBuild = $other->builds()->create(['status' => Build::STATUS_SUCCEEDED, 'revision' => 'mismatched-revision']);
        $this->repository->webhookDeliveries()->create(['delivery_id' => 'mismatched-delivery', 'build_id' => $foreignBuild->id, 'status' => 'queued', 'revision' => 'mismatched-revision']);
        $filters = ['delivery_status' => null, 'delivery_date_from' => null, 'delivery_date_to' => null];
        $history = app(RepositoryWebhookDeliveryHistoryQuery::class);

        $this->assertEqualsCanonicalizing([$visible->id, $pending->id], $history->for($this->repository, $filters, $this->actor)->pluck('id')->all());
        $this->assertSame(2, $history->metrics($this->repository, $filters, $this->actor)['total']);
        $this->assertSame(1, $history->metrics($this->repository, [...$filters, 'delivery_status' => 'queued'], $this->actor)['total']);
        $this->assertSame([$visible->id], $history->for($this->repository, [...$filters, 'delivery_status' => 'queued'], $this->actor)->latest('id')->limit(1)->pluck('id')->all());
        ob_start();
        app(RepositoryWebhookDeliveryHistoryExporter::class)->stream($this->repository, $filters, $this->actor)->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('allowed-delivery', $csv);
        $this->assertStringContainsString('pending-delivery', $csv);
        $this->assertStringNotContainsString('hidden-', $csv);
        $this->assertStringNotContainsString('mismatched-', $csv);
    }

    public function test_project_graph_preserves_parent_but_omits_denied_latest_build_and_preview(): void
    {
        $this->build(Build::STATUS_SUCCEEDED, 'earlier-visible', 30);
        $latest = $this->build(Build::STATUS_SUCCEEDED, 'private-latest', 90);
        $this->deny('build', $latest->id);
        $this->project->previews()->create([
            'source_repository_id' => $this->repository->id, 'source_environment_id' => $this->environment->id,
            'initialization_build_id' => $latest->id, 'pull_request_number' => 7, 'source_branch' => 'private-branch',
            'revision' => 'private-latest', 'title' => 'Private preview', 'url' => 'preview.example.test', 'last_activity_at' => now(),
        ]);
        $view = app(ProjectController::class)->show($this->request(), $this->project, app(Entitlements::class));
        $project = $view->getData()['project'];
        $repository = $project->environments->sole()->website->repositories->sole();
        $this->assertSame($this->project->id, $project->id);
        $this->assertSame($this->repository->id, $repository->id);
        $this->assertNull($repository->latestBuild);
        $this->assertNull($repository->latestSuccessfulBuild);
        $this->assertCount(0, $project->previews);
        config(['platform.products.deployer.auth_authority' => 'legacy']);
        $legacy = app(ProjectController::class)->show($this->request(), $this->project->fresh(), app(Entitlements::class))->getData()['project'];
        $this->assertSame($latest->id, $legacy->environments->sole()->website->repositories->sole()->latestBuild->id);
        $this->assertCount(1, $legacy->previews);
    }

    public function test_project_graph_keeps_environment_but_omits_its_denied_placement(): void
    {
        $this->deny('server', $this->environment->server_id);
        $project = app(ProjectController::class)->show($this->request(), $this->project, app(Entitlements::class))->getData()['project'];
        $environment = $project->environments->sole();
        $this->assertSame($this->environment->id, $environment->id);
        $this->assertNull($environment->server);
        $this->assertNull($environment->website);
        $this->assertSame(0, $project->environments->filter(fn ($environment) => $environment->website !== null)->count());
    }

    public function test_dashboard_attention_and_webhook_totals_omit_denied_latest_build(): void
    {
        $this->build(Build::STATUS_SUCCEEDED, 'earlier-visible', 30);
        $latest = $this->build(Build::STATUS_FAILED, 'private-latest', 90);
        $this->deny('build', $latest->id);
        $this->repository->webhookDeliveries()->create(['delivery_id' => 'private-delivery', 'build_id' => $latest->id, 'status' => 'queued', 'revision' => 'private-latest']);
        $systemHealth = Mockery::mock(SystemHealth::class);
        $systemHealth->shouldReceive('summary')->andReturn([]);
        $dialogs = Mockery::mock(DashboardCreationDialogData::class);
        $dialogs->shouldReceive('for')->andReturn([]);
        $data = app(DashboardController::class)->__invoke($this->request(), $systemHealth, app(PublicPlatformStatus::class), app(PlanLimits::class), $dialogs)->getData();

        $this->assertSame(1, $data['stats']['repositories']);
        $this->assertSame(1, $data['stats']['builds']);
        $this->assertSame(0, $data['attentionCounts']['deployments']);
        $this->assertCount(0, $data['attentionRepositories']);
        $this->assertSame(0, $data['webhookDeliveryCounts']['queued']);
        $this->assertCount(0, $data['recentWebhookDeliveries']);
    }

    private function request(): Request
    {
        $request = Request::create('/dashboard');
        $request->setUserResolver(fn (): User => $this->actor);

        return $request;
    }

    private function build(string $status, string $revision, int $duration): Build
    {
        return $this->repository->builds()->create([
            'environment_id' => $this->environment->id, 'status' => $status, 'revision' => $revision,
            'started_at' => now()->subSeconds($duration), 'finished_at' => now(),
        ]);
    }

    private function deny(string $type, int $sourceId): void
    {
        $project = CoreProject::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Restricted', 'slug' => $type.'-'.$sourceId, 'status' => 'active']);
        ProjectMembership::query()->create(['project_id' => $project->id, 'user_id' => $this->principal->id, 'role' => 'member', 'status' => 'revoked', 'revoked_at' => now()]);
        ProjectProduct::query()->create(['project_id' => $project->id, 'product' => 'deployer', 'status' => 'active']);
        ProjectResource::query()->create(['project_id' => $project->id, 'product' => 'deployer', 'resource_type' => $type, 'resource_id' => (string) $sourceId, 'status' => 'active']);
    }
}
