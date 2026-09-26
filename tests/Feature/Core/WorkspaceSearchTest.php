<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\WorkspaceSearchProvider;
use App\Core\Data\Search\WorkspaceSearchResult;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\Search\ProductWorkspaceSearch;
use App\Core\Services\Search\WorkspaceSearch;
use App\Core\Services\Search\WorkspaceSearchProviderRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Deployer\Services\Core\DeployerWorkspaceSearchProvider;
use App\Modules\Monitor\Models\User as MonitorUser;
use App\Modules\Monitor\Services\Core\MonitorWorkspaceSearchProvider;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceSearchTest extends TestCase
{
    private string $userId;

    private string $workspaceId;

    private string $membershipId;

    private bool $monitorSearchTablesCreated = false;

    private bool $deployerSearchTablesCreated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->membershipId = (string) Str::ulid();

        DB::connection('core')->table('users')->insert([
            'id' => $this->userId,
            'name' => 'Search user',
            'email' => 'search@example.test',
            'email_normalized' => 'search@example.test',
            'password' => 'not-a-real-password-hash',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $this->userId,
            'name' => 'Search workspace',
            'slug' => 'search-workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $this->membershipId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        foreach ([
            'project_memberships',
            'projects',
            'workspace_product_access',
            'workspace_memberships',
            'legacy_identity_maps',
            'workspaces',
            'users',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        if ($this->monitorSearchTablesCreated) {
            foreach (['incidents', 'monitors', 'alert_rules', 'environments', 'applications', 'user_workspace', 'workspaces', 'users'] as $table) {
                Schema::connection('monitor')->dropIfExists($table);
            }
        }

        if ($this->deployerSearchTablesCreated) {
            foreach (['builds', 'repositories', 'environments', 'projects', 'servers', 'organization_user', 'organizations', 'users'] as $table) {
                Schema::connection('deployer')->dropIfExists($table);
            }
        }

        parent::tearDown();
    }

    public function test_search_returns_only_workspace_projects_the_user_can_open_and_authorized_product_results(): void
    {
        $visibleProjectId = $this->project('Checkout portal', true);
        $hiddenProjectId = $this->project('Checkout private', false);
        $this->grant('monitor');
        $this->grant('analytics');

        $registry = new WorkspaceSearchProviderRegistry;
        $registry->register('monitor', new class implements WorkspaceSearchProvider
        {
            public function search(PlatformUser $user, Workspace $workspace, string $query): array
            {
                return [new WorkspaceSearchResult('Incident', 'Incident #17', 'Open', '/monitor/incidents/17')];
            }
        });
        $registry->register('analytics', new class implements WorkspaceSearchProvider
        {
            public function search(PlatformUser $user, Workspace $workspace, string $query): array
            {
                throw new QueryException('analytics', 'select', [], new \RuntimeException('offline'));
            }
        });

        $result = (new WorkspaceSearch($registry, app(WorkspaceProjectAccess::class)))
            ->forWorkspace($this->user(), $this->workspace(), 'Checkout');

        $this->assertSame('Checkout', $result['query']);
        $this->assertSame(['core', 'monitor'], array_column($result['groups'], 'product'));
        $this->assertSame(1, $result['groups'][0]['count']);
        $this->assertSame('Checkout portal', $result['groups'][0]['results'][0]['title']);
        $this->assertSame(route('core.projects.show', [$this->workspaceId, $visibleProjectId]), $result['groups'][0]['results'][0]['url']);
        $this->assertSame('Incident #17', $result['groups'][1]['results'][0]['title']);
        $this->assertSame([['product' => 'analytics', 'label' => 'Analytics']], $result['unavailable']);
        $this->assertStringNotContainsString($hiddenProjectId, json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_search_skips_a_product_provider_when_the_workspace_grant_is_missing_or_revoked(): void
    {
        $this->project('Checkout portal', true);
        $registry = new WorkspaceSearchProviderRegistry;
        $registry->register('monitor', new class implements WorkspaceSearchProvider
        {
            public function search(PlatformUser $user, Workspace $workspace, string $query): array
            {
                throw new \LogicException('The provider must not run without a current product grant.');
            }
        });

        $result = (new WorkspaceSearch($registry, app(WorkspaceProjectAccess::class)))
            ->forWorkspace($this->user(), $this->workspace(), 'Checkout');

        $this->assertSame(['core'], array_column($result['groups'], 'product'));
        $this->assertSame([], $result['unavailable']);

        $this->grant('monitor', revoked: true);
        $result = (new WorkspaceSearch($registry, app(WorkspaceProjectAccess::class)))
            ->forWorkspace($this->user(), $this->workspace(), 'Checkout');

        $this->assertSame(['core'], array_column($result['groups'], 'product'));
        $this->assertSame([], $result['unavailable']);

        DB::connection('core')->table('workspace_memberships')
            ->where('id', $this->membershipId)
            ->update(['expires_at' => now()->subSecond()]);
        $result = (new WorkspaceSearch($registry, app(WorkspaceProjectAccess::class)))
            ->forWorkspace($this->user(), $this->workspace(), 'Checkout');

        $this->assertSame([], $result['groups']);
        $this->assertSame([], $result['unavailable']);
    }

    public function test_search_requires_two_characters_and_escapes_sql_wildcards(): void
    {
        $this->project('Release 100% complete', true);
        $search = new WorkspaceSearch(new WorkspaceSearchProviderRegistry, app(WorkspaceProjectAccess::class));

        $this->assertSame([], $search->forWorkspace($this->user(), $this->workspace(), '%')['groups']);
        $literal = $search->forWorkspace($this->user(), $this->workspace(), '100%');
        $wildcard = $search->forWorkspace($this->user(), $this->workspace(), '100x');

        $this->assertSame(['Release 100% complete'], array_column($literal['groups'][0]['results'], 'title'));
        $this->assertSame([], $wildcard['groups']);
    }

    public function test_search_endpoint_requires_workspace_membership_and_disables_shared_caching(): void
    {
        $this->actingAs($this->user(), 'platform');

        $response = $this->getJson(route('core.workspace.search', [
            'workspace' => $this->workspaceId,
            'q' => 'checkout',
        ]));

        $response->assertOk()
            ->assertJsonPath('query', 'checkout')
            ->assertJsonPath('groups', []);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $otherWorkspaceId = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $otherWorkspaceId,
            'owner_user_id' => (string) Str::ulid(),
            'name' => 'Another workspace',
            'slug' => 'another-workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson(route('core.workspace.search', [
            'workspace' => $otherWorkspaceId,
            'q' => 'checkout',
        ]))->assertNotFound();
    }

    public function test_product_search_bridge_requires_reconciled_identity_workspace_and_membership(): void
    {
        $this->project('Core infrastructure', true);
        $sourceUserId = '4701';
        $sourceWorkspaceId = '7301';
        $principal = (new MonitorUser)->forceFill(['id' => $sourceUserId]);
        $this->mapIdentity('user', $sourceUserId, 'user', $this->userId, 'reconciled');
        $this->mapIdentity('workspace', $sourceWorkspaceId, 'workspace', $this->workspaceId, 'pending');
        $search = app(ProductWorkspaceSearch::class);

        $this->assertNull($search->fromSourceWorkspace(
            $principal,
            'monitor',
            'workspace',
            $sourceWorkspaceId,
            'Core',
        ));

        DB::connection('core')->table('legacy_identity_maps')
            ->where('source_entity', 'workspace')
            ->where('source_id', $sourceWorkspaceId)
            ->update(['status' => 'reconciled']);

        $results = $search->fromSourceWorkspace(
            $principal,
            'monitor',
            'workspace',
            $sourceWorkspaceId,
            'Core',
        );

        $this->assertNotNull($results);
        $this->assertSame(['core'], array_column($results['groups'], 'product'));
        $this->assertSame('Core infrastructure', $results['groups'][0]['results'][0]['title']);

        DB::connection('core')->table('workspace_memberships')
            ->where('id', $this->membershipId)
            ->update(['status' => 'revoked']);

        $this->assertNull($search->fromSourceWorkspace(
            $principal,
            'monitor',
            'workspace',
            $sourceWorkspaceId,
            'Core',
        ));
    }

    public function test_monitor_incident_search_excludes_incidents_with_sources_in_different_workspaces(): void
    {
        $this->createMonitorSearchTables();

        $sourceUserId = '501';
        $mappedWorkspaceId = '81';
        $otherSourceWorkspaceId = '82';
        $otherCanonicalWorkspaceId = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $otherCanonicalWorkspaceId,
            'owner_user_id' => (string) Str::ulid(),
            'name' => 'Other workspace',
            'slug' => 'other-workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->mapIdentity('user', $sourceUserId, 'user', $this->userId, 'reconciled');
        $this->mapIdentity('workspace', $mappedWorkspaceId, 'workspace', $this->workspaceId, 'reconciled');
        $this->mapIdentity('workspace', $otherSourceWorkspaceId, 'workspace', $otherCanonicalWorkspaceId, 'reconciled');

        DB::connection('monitor')->table('users')->insert(['id' => (int) $sourceUserId, 'name' => 'Monitor user']);
        DB::connection('monitor')->table('workspaces')->insert([
            ['id' => (int) $mappedWorkspaceId, 'owner_id' => (int) $sourceUserId, 'name' => 'Mapped', 'slug' => 'mapped'],
            ['id' => (int) $otherSourceWorkspaceId, 'owner_id' => (int) $sourceUserId, 'name' => 'Other', 'slug' => 'other'],
        ]);
        DB::connection('monitor')->table('user_workspace')->insert([
            'workspace_id' => (int) $mappedWorkspaceId,
            'user_id' => (int) $sourceUserId,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('applications')->insert([
            ['id' => 91, 'workspace_id' => (int) $mappedWorkspaceId, 'name' => 'Mapped app'],
            ['id' => 92, 'workspace_id' => (int) $otherSourceWorkspaceId, 'name' => 'Other app'],
        ]);
        DB::connection('monitor')->table('environments')->insert([
            ['id' => 101, 'application_id' => 91, 'name' => 'Production'],
            ['id' => 102, 'application_id' => 92, 'name' => 'Production'],
        ]);
        DB::connection('monitor')->table('alert_rules')->insert([
            'id' => 201,
            'environment_id' => 101,
            'name' => 'Mapped alert rule',
        ]);
        DB::connection('monitor')->table('monitors')->insert([
            'id' => 301,
            'environment_id' => 102,
            'name' => 'Other workspace monitor',
            'type' => 'http',
        ]);
        DB::connection('monitor')->table('incidents')->insert([
            ['id' => 401, 'alert_rule_id' => 201, 'monitor_id' => null, 'status' => 'open', 'opened_at' => now()],
            ['id' => 402, 'alert_rule_id' => 201, 'monitor_id' => 301, 'status' => 'open', 'opened_at' => now()],
        ]);

        if (! Route::has('monitor.incidents.show')) {
            Route::get('/monitor/incidents/{incident}', fn () => response()->noContent())
                ->name('monitor.incidents.show');
        }

        $results = (new MonitorWorkspaceSearchProvider(app(LegacyIdentityResolver::class)))
            ->search($this->user(), $this->workspace(), 'open');

        $this->assertCount(1, $results);
        $this->assertSame('Incident #401', $results[0]->title);
        parse_str((string) parse_url($results[0]->url, PHP_URL_QUERY), $query);
        $this->assertSame((int) $mappedWorkspaceId, (int) ($query['workspace_id'] ?? 0));
    }

    public function test_deployer_search_includes_environment_only_builds_and_excludes_cross_organization_builds(): void
    {
        $this->createDeployerSearchTables();

        $sourceUserId = '601';
        $mappedOrganizationId = '91';
        $otherOrganizationId = '92';
        $this->mapIdentity('user', $sourceUserId, 'user', $this->userId, 'reconciled', 'deployer');
        $this->mapIdentity('organization', $mappedOrganizationId, 'workspace', $this->workspaceId, 'reconciled', 'deployer');
        $this->mapIdentity('organization', $otherOrganizationId, 'workspace', $this->workspaceId, 'reconciled', 'deployer');

        DB::connection('deployer')->table('users')->insert(['id' => (int) $sourceUserId, 'name' => 'Deployer user']);
        DB::connection('deployer')->table('organizations')->insert([
            ['id' => (int) $mappedOrganizationId, 'owner_id' => (int) $sourceUserId],
            ['id' => (int) $otherOrganizationId, 'owner_id' => (int) $sourceUserId],
        ]);
        DB::connection('deployer')->table('repositories')->insert([
            'id' => 101,
            'organization_id' => (int) $mappedOrganizationId,
            'name' => 'Mapped repository',
            'deleted_at' => null,
        ]);
        DB::connection('deployer')->table('projects')->insert([
            ['id' => 301, 'organization_id' => (int) $mappedOrganizationId],
            ['id' => 302, 'organization_id' => (int) $otherOrganizationId],
        ]);
        DB::connection('deployer')->table('environments')->insert([
            ['id' => 201, 'project_id' => 301],
            ['id' => 202, 'project_id' => 302],
        ]);
        DB::connection('deployer')->table('builds')->insert([
            ['id' => 401, 'repository_id' => null, 'environment_id' => 201, 'status' => 'failed', 'revision' => 'abc123'],
            ['id' => 402, 'repository_id' => 101, 'environment_id' => 202, 'status' => 'failed', 'revision' => 'def456'],
        ]);

        if (! Route::has('servers.show')) {
            Route::get('/deployer/servers/{server}', fn () => response()->noContent())->name('servers.show');
        }
        if (! Route::has('builds.show')) {
            Route::get('/deployer/builds/{build}', fn () => response()->noContent())->name('builds.show');
        }

        $results = (new DeployerWorkspaceSearchProvider(app(LegacyIdentityResolver::class)))
            ->search($this->user(), $this->workspace(), 'failed');

        $this->assertCount(1, $results);
        $this->assertSame('Build #401', $results[0]->title);
        parse_str((string) parse_url($results[0]->url, PHP_URL_QUERY), $query);
        $this->assertSame((int) $mappedOrganizationId, (int) ($query['organization_id'] ?? 0));
    }

    private function createMonitorSearchTables(): void
    {
        foreach (['incidents', 'monitors', 'alert_rules', 'environments', 'applications', 'user_workspace', 'workspaces', 'users'] as $table) {
            Schema::connection('monitor')->dropIfExists($table);
        }

        Schema::connection('monitor')->create('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
        });
        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('user_workspace', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('applications', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('environments', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('application_id');
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('alert_rules', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('environment_id');
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('monitors', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('environment_id');
            $table->string('name');
            $table->string('type');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('incidents', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('alert_rule_id')->nullable();
            $table->unsignedBigInteger('monitor_id')->nullable();
            $table->string('status');
            $table->timestamp('opened_at');
        });

        $this->monitorSearchTablesCreated = true;
    }

    private function createDeployerSearchTables(): void
    {
        foreach (['builds', 'repositories', 'environments', 'projects', 'servers', 'organization_user', 'organizations', 'users'] as $table) {
            Schema::connection('deployer')->dropIfExists($table);
        }

        Schema::connection('deployer')->create('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
        });
        Schema::connection('deployer')->create('organizations', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('owner_id');
        });
        Schema::connection('deployer')->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('servers', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->string('name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('provisioning_status')->nullable();
        });
        Schema::connection('deployer')->create('repositories', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::connection('deployer')->create('projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('organization_id');
        });
        Schema::connection('deployer')->create('environments', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('project_id');
        });
        Schema::connection('deployer')->create('builds', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('repository_id')->nullable();
            $table->unsignedBigInteger('environment_id')->nullable();
            $table->string('status');
            $table->string('revision')->nullable();
        });

        $this->deployerSearchTablesCreated = true;
    }

    private function project(string $name, bool $member): string
    {
        $projectId = (string) Str::ulid();
        DB::connection('core')->table('projects')->insert([
            'id' => $projectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->userId,
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($member) {
            DB::connection('core')->table('project_memberships')->insert([
                'id' => (string) Str::ulid(),
                'project_id' => $projectId,
                'user_id' => $this->userId,
                'role' => 'owner',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $projectId;
    }

    private function grant(string $product, bool $revoked = false): void
    {
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $this->membershipId,
            'product' => $product,
            'role' => 'member',
            'status' => 'active',
            'granted_at' => now(),
            'revoked_at' => $revoked ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mapIdentity(
        string $sourceEntity,
        string $sourceId,
        string $canonicalEntity,
        string $canonicalId,
        string $status,
        string $product = 'monitor',
    ): void {
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'source_product' => $product,
            'source_entity' => $sourceEntity,
            'source_id' => $sourceId,
            'canonical_entity' => $canonicalEntity,
            'canonical_id' => $canonicalId,
            'status' => $status,
        ]);
    }

    private function user(): PlatformUser
    {
        return PlatformUser::query()->findOrFail($this->userId);
    }

    private function workspace(): Workspace
    {
        return Workspace::query()->findOrFail($this->workspaceId);
    }

    private function createTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('email');
            $table->string('email_normalized');
            $table->string('password');
            $table->string('status');
            $table->timestamps();
        });
        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->string('source_product');
            $table->string('source_entity');
            $table->string('source_id');
            $table->string('canonical_entity');
            $table->string('canonical_id');
            $table->string('status');
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26);
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->json('settings')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('membership_id', 26);
            $table->string('product');
            $table->string('role');
            $table->string('status');
            $table->char('granted_by_user_id', 26)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('created_by_user_id', 26);
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('user_id', 26);
            $table->string('role');
            $table->string('status');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }
}
