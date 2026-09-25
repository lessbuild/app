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
use App\Modules\Deployer\Http\Controllers\RepositoriesController;
use App\Modules\Deployer\Http\Controllers\WebsitesController;
use App\Modules\Deployer\Http\Requests\RepositoryIndexRequest;
use App\Modules\Deployer\Http\Requests\RepositoryWebhookDeliveryRequest;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\Core\DeployerResourceProjection;
use App\Modules\Deployer\Services\DeploymentGate;
use App\Modules\Deployer\Services\DeploymentPreflight;
use App\Modules\Deployer\Services\DeploymentPreflightGuidance;
use App\Modules\Deployer\Services\RepositoryDeploymentInsightsQuery;
use App\Modules\Deployer\Services\RepositoryInventoryExporter;
use App\Modules\Deployer\Services\RepositoryInventoryQuery;
use App\Modules\Deployer\Services\WebsiteInventoryExporter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/** Written for the deferred plan-completion regression run. */
final class DeployerNestedProjectionAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PlatformUser $principal;

    private Workspace $workspace;

    private Provider $provider;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('platform:migrate', ['module' => 'core']);
        Queue::fake();
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
        $this->principal = PlatformUser::query()->create([
            'name' => 'Viewer', 'email' => 'nested@example.test', 'email_normalized' => 'nested@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $this->workspace = Workspace::query()->create([
            'owner_user_id' => $this->principal->getKey(), 'name' => 'Nested', 'slug' => 'nested', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->principal->getKey(), 'role' => 'owner', 'status' => 'active',
        ]);
        WorkspaceProductAccess::query()->create(['membership_id' => $membership->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active']);
        $this->map('user', $this->actor->id, 'user', $this->principal->getKey());
        $this->map('organization', $this->actor->current_organization_id, 'workspace', $this->workspace->getKey());
        $this->provider = $this->actor->providers()->create(['name' => 'Source', 'provider' => 'github', 'description' => 'Source', 'token' => 'unused']);
        $server = $this->actor->servers()->create(['provider_id' => $this->provider->id, 'name' => 'Server', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $this->website = $this->actor->websites()->create([
            'server_id' => $server->id, 'name' => 'Visible website', 'url' => 'nested.example.test', 'description' => 'Site', 'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
    }

    public function test_website_detail_filters_denied_and_foreign_repositories_before_pagination_without_hiding_parent(): void
    {
        $denied = $this->repository('Hidden repository');
        $allowed = $this->repository('Visible repository');
        $this->deny('repository', $denied->id);
        $foreignOwner = User::factory()->create();
        $foreign = $foreignOwner->repositories()->create([
            'organization_id' => $foreignOwner->current_organization_id, 'provider_id' => $this->provider->id, 'website_id' => $this->website->id,
            'name' => 'Foreign repository', 'url' => 'github.com/example/foreign.git', 'description' => 'Foreign',
        ]);
        $request = Request::create('/websites/'.$this->website->id);
        $request->setUserResolver(fn () => $this->actor);
        $view = app(WebsitesController::class)->show($request, $this->website);
        $repositories = $view->getData()['repositories'];

        $this->assertTrue($this->actor->can('view', $this->website));
        $this->assertSame(1, $repositories->total());
        $this->assertSame([$allowed->id], $repositories->getCollection()->pluck('id')->all());
        $this->assertDatabaseHas('repositories', ['id' => $denied->id]);
        $this->assertDatabaseHas('repositories', ['id' => $foreign->id]);
    }

    public function test_website_csv_counts_only_visible_nested_repositories(): void
    {
        $denied = $this->repository('Hidden repository');
        $this->repository('Visible repository');
        $this->deny('repository', $denied->id);
        $response = app(WebsiteInventoryExporter::class)->stream($this->actor, [
            'search' => null, 'status' => null, 'health' => null, 'attention' => null, 'provisioning' => null,
        ]);
        $rows = $this->csv($response);
        $this->assertCount(2, $rows);
        $this->assertSame('Repository count', $rows[0][14]);
        $this->assertSame('1', $rows[1][14]);
    }

    public function test_denied_exact_latest_build_is_omitted_without_replacing_it_with_an_older_visible_build(): void
    {
        $repository = $this->repository('Visible repository');
        $older = $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED, 'revision' => 'older-visible']);
        $denied = $repository->builds()->create(['status' => Build::STATUS_FAILED, 'revision' => 'denied-latest']);
        $this->deny('build', $denied->id);
        $request = $this->formRequest(RepositoryIndexRequest::class);
        $view = app(RepositoriesController::class)->index($request);
        $item = $view->getData()['repositories']->getCollection()->sole();

        $this->assertSame($repository->id, $item->id);
        $this->assertNull($item->latestBuild);
        $this->assertSame([$older->id], app(DeployerResourceProjection::class)->builds($repository->builds(), $this->actor)->pluck('builds.id')->all());
        $csv = $this->csv(app(RepositoryInventoryExporter::class)->stream($this->actor, $this->filters()));
        $this->assertCount(2, $csv);
        $this->assertSame('', $csv[1][10]);
        $this->assertSame('', $csv[1][11]);
        $this->assertSame('', $csv[1][12]);
    }

    public function test_latest_status_filters_and_metrics_hide_denied_status_but_preserve_actual_never_deployed_semantics(): void
    {
        $repository = $this->repository('Visible repository');
        $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $denied = $repository->builds()->create(['status' => Build::STATUS_FAILED]);
        $this->deny('build', $denied->id);
        $query = app(RepositoryInventoryQuery::class);
        $metrics = $query->metrics($this->actor, $this->filters());
        $this->assertSame(1, $metrics['total']);
        $this->assertSame(0, $metrics['failed']);
        $this->assertSame(0, $metrics['succeeded']);
        $this->assertSame(0, $metrics['never_deployed']);
        foreach (['none', Build::STATUS_FAILED, Build::STATUS_SUCCEEDED] as $status) {
            $this->assertSame(0, $query->for($this->actor, [...$this->filters(), 'status' => $status])->count());
        }
    }

    public function test_repository_detail_keeps_allowed_history_and_separate_exact_latest_projection(): void
    {
        $repository = $this->repository('Visible repository');
        $allowed = $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED, 'revision' => 'allowed-history']);
        $denied = $repository->builds()->create(['status' => Build::STATUS_FAILED, 'revision' => 'denied-latest']);
        $this->deny('build', $denied->id);
        $request = $this->formRequest(RepositoryWebhookDeliveryRequest::class);
        $view = app(RepositoriesController::class)->show($request, $repository,
            app(DeploymentGate::class), app(DeploymentPreflight::class), app(DeploymentPreflightGuidance::class), app(RepositoryDeploymentInsightsQuery::class));
        $data = $view->getData();

        $this->assertSame([$allowed->id], $data['builds']->pluck('id')->all());
        $this->assertNull($data['latestBuild']);
        $this->assertSame(1, $data['deploymentMetrics']['total']);
        $this->assertSame(0, $data['deploymentMetrics']['failed']);
        $this->assertFalse($data['isFirstDeployment']);
    }

    public function test_legacy_projection_and_latest_status_behavior_remains_available(): void
    {
        $repository = $this->repository('Visible repository');
        $denied = $repository->builds()->create(['status' => Build::STATUS_FAILED]);
        $this->deny('build', $denied->id);
        config(['platform.products.deployer.auth_authority' => 'legacy']);

        $metrics = app(RepositoryInventoryQuery::class)->metrics($this->actor, $this->filters());
        $this->assertSame(1, $metrics['failed']);
        $repository->load(['latestBuild' => fn ($query) => app(DeployerResourceProjection::class)->builds($query, $this->actor)]);
        $this->assertSame($denied->id, $repository->latestBuild->id);
    }

    private function repository(string $name): Repository
    {
        return $this->actor->repositories()->create([
            'provider_id' => $this->provider->id, 'website_id' => $this->website->id,
            'name' => $name, 'description' => $name, 'url' => 'github.com/example/source.git', 'branch' => 'main',
        ]);
    }

    private function deny(string $type, int $id): void
    {
        $project = CoreProject::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'name' => $type.'-'.$id, 'slug' => $type.'-'.$id, 'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $project->getKey(), 'user_id' => $this->principal->getKey(), 'role' => 'member', 'status' => 'revoked', 'revoked_at' => now(),
        ]);
        ProjectProduct::query()->create(['project_id' => $project->getKey(), 'product' => 'deployer', 'status' => 'active']);
        ProjectResource::query()->create([
            'project_id' => $project->getKey(), 'product' => 'deployer', 'resource_type' => $type, 'resource_id' => (string) $id, 'status' => 'active',
        ]);
    }

    private function map(string $sourceType, int $sourceId, string $canonicalType, string $canonicalId): void
    {
        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer', 'source_entity' => $sourceType, 'source_id' => (string) $sourceId,
            'canonical_entity' => $canonicalType, 'canonical_id' => $canonicalId, 'status' => 'reconciled',
        ]);
    }

    private function filters(): array
    {
        return ['search' => null, 'provider_id' => null, 'website_id' => null, 'status' => null];
    }

    /** @template T of FormRequest @param class-string<T> $class @return T */
    private function formRequest(string $class): FormRequest
    {
        $request = $class::create('/repositories');
        $request->setUserResolver(fn () => $this->actor);
        $request->setValidator(Validator::make($request->validationData(), $request->rules()));

        return $request;
    }

    private function csv(StreamedResponse $response): array
    {
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        $lines = preg_split('/\r?\n/', trim(substr($content, 3)));

        return array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines);
    }
}
