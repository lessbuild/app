<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectProductLink;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Services\ProjectProductLinkRegistry;
use App\Core\Services\ProjectProductLinks;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Analytics\Actions\Workspaces\EnsurePersonalWorkspace;
use App\Modules\Analytics\Services\Core\AnalyticsProjectLink;
use App\Modules\Monitor\Models\Application as MonitorApplication;
use App\Modules\Monitor\Models\User as MonitorUser;
use App\Modules\Monitor\Services\Core\MonitorProjectLink;
use App\Modules\Monitor\Services\Core\MonitorProjectSummary;
use App\Modules\Monitor\Services\CurrentWorkspace as MonitorCurrentWorkspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProjectProductLinksTest extends TestCase
{
    private const PLATFORM_USER_ID = '01J8AA00000000000000000000';

    private const PROJECT_ID = '01J8AA00000000000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCoreTables();
        $this->createCoreAccessTables();
        $this->createMonitorTables();
        $this->createAnalyticsTables();
    }

    protected function tearDown(): void
    {
        foreach (['sites', 'workspace_user', 'workspaces', 'users'] as $table) {
            Schema::connection('analytics')->dropIfExists($table);
        }

        foreach (['incidents', 'alert_rules', 'monitors', 'environments', 'applications', 'user_workspace', 'workspaces', 'users'] as $table) {
            Schema::connection('monitor')->dropIfExists($table);
        }

        foreach ([
            'project_products',
            'workspace_product_access',
            'project_memberships',
            'workspace_memberships',
            'workspaces',
            'project_resources',
            'legacy_identity_maps',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_monitor_link_targets_the_mapped_application_only_for_an_authorized_source_member(): void
    {
        Route::get('/monitor/applications/{application}', static fn () => null)
            ->name('monitor.applications.show');
        Route::getRoutes()->refreshNameLookups();
        $this->addIdentity('monitor', '17');
        $this->addProjectResource('monitor', 'application', '31');
        $this->addMonitorWorkspaceAndApplication(memberId: 17, workspaceId: 50, applicationId: 31);

        $url = app(MonitorProjectLink::class)->resolve($this->platformUser(), $this->project());

        $this->assertSame('/monitor/applications/31', parse_url($url, PHP_URL_PATH));

        DB::connection('monitor')->table('user_workspace')->delete();

        $this->assertNull(app(MonitorProjectLink::class)->resolve($this->platformUser(), $this->project()));
    }

    public function test_analytics_link_keeps_the_mapped_site_in_the_url_and_selects_its_workspace_per_request(): void
    {
        Route::get('/analytics/dashboard', static fn () => null)->name('analytics.dashboard');
        Route::getRoutes()->refreshNameLookups();
        $this->addIdentity('analytics', '23');
        $this->addProjectResource('analytics', 'site', '71');
        $this->addAnalyticsWorkspacesAndSite(memberId: 23, firstWorkspaceId: 100, secondWorkspaceId: 200, siteId: 71);

        $platformUser = $this->platformUser();
        $url = app(AnalyticsProjectLink::class)->resolve($platformUser, $this->project());

        $this->assertSame('/analytics/dashboard', parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('71', $query['site']);

        session()->put('analytics_workspace_id', 100);
        $workspace = app(EnsurePersonalWorkspace::class)->handle($platformUser, 71);

        $this->assertSame(200, $workspace->getKey());
        $this->assertSame(100, session('analytics_workspace_id'));
    }

    public function test_monitor_application_deep_link_does_not_retarget_the_session_workspace(): void
    {
        $this->addMonitorWorkspaceAndApplication(memberId: 17, workspaceId: 200, applicationId: 31);
        DB::connection('monitor')->table('workspaces')->insert([
            'id' => 100,
            'owner_id' => 17,
            'name' => 'Other workspace',
            'slug' => 'other-workspace',
            'plan' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('user_workspace')->insert([
            'workspace_id' => 100,
            'user_id' => 17,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/applications/31', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('workspace_id', 100);
        $request->setUserResolver(fn (): MonitorUser => MonitorUser::query()->findOrFail(17));
        $route = new \Illuminate\Routing\Route('GET', '/applications/{application}', []);
        $route->bind($request);
        $route->setParameter('application', MonitorApplication::query()->findOrFail(31));
        $request->setRouteResolver(static fn () => $route);

        $workspace = (new MonitorCurrentWorkspace($request))->get();

        $this->assertSame(200, $workspace->getKey());
        $this->assertSame(100, $request->session()->get('workspace_id'));
    }

    public function test_monitor_summary_marks_stale_checks_for_attention(): void
    {
        $this->addIdentity('monitor', '17');
        $this->addProjectResource('monitor', 'application', '31');
        $this->addMonitorWorkspaceAndApplication(memberId: 17, workspaceId: 50, applicationId: 31);
        DB::connection('monitor')->table('environments')->insert([
            'id' => 41,
            'application_id' => 31,
            'name' => 'Production',
            'slug' => 'production',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('monitors')->insert([
            [
                'id' => 51,
                'environment_id' => 41,
                'name' => 'Homepage',
                'type' => 'http',
                'health' => 'up',
                'enabled' => true,
                'checked_at' => now(),
                'interval_minutes' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 52,
                'environment_id' => 41,
                'name' => 'API monitor',
                'type' => 'http',
                'health' => 'up',
                'enabled' => true,
                'checked_at' => null,
                'interval_minutes' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $summary = (new MonitorProjectSummary(app(MonitorProjectLink::class)))
            ->summarize($this->platformUser(), $this->project());

        $this->assertNotNull($summary);
        $this->assertSame(ProjectProductSnapshotState::Attention, $summary->state);
        $this->assertSame('0 open incidents · 1 checks up · 0 checks down · 1 unknown · 0 paused', $summary->detail);
    }

    public function test_core_project_membership_and_product_grant_gate_module_destinations(): void
    {
        $workspaceId = '01J8AA00000000000000000020';
        $membershipId = '01J8AA00000000000000000021';
        $project = $this->project();
        $project->setAttribute('workspace_id', $workspaceId);
        $project->setAttribute('status', 'active');
        $project->setAttribute('archived_at', null);

        DB::connection('core')->table('workspaces')->insert([
            'id' => $workspaceId,
            'owner_user_id' => self::PLATFORM_USER_ID,
            'name' => 'Shared workspace',
            'slug' => 'shared-workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $workspaceId,
            'user_id' => self::PLATFORM_USER_ID,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => '01J8AA00000000000000000022',
            'project_id' => self::PROJECT_ID,
            'user_id' => self::PLATFORM_USER_ID,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_products')->insert([
            'id' => '01J8AA00000000000000000023',
            'project_id' => self::PROJECT_ID,
            'product' => 'monitor',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $registry = new ProjectProductLinkRegistry;
        $registry->register('monitor', new class implements ProjectProductLink
        {
            public function resolve(PlatformUser $user, Project $project): ?string
            {
                return 'https://monitor.example.test/applications/31';
            }
        });
        $links = new ProjectProductLinks($registry, app(WorkspaceProjectAccess::class));

        $this->assertSame([], $links->forProject($this->platformUser(), $project, ['monitor']));

        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => '01J8AA00000000000000000024',
            'membership_id' => $membershipId,
            'product' => 'monitor',
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([
            'monitor' => 'https://monitor.example.test/applications/31',
        ], $links->forProject($this->platformUser(), $project, ['monitor']));

        DB::connection('core')->table('project_memberships')
            ->where('project_id', self::PROJECT_ID)
            ->update(['status' => 'revoked']);

        $this->assertSame([], $links->forProject($this->platformUser(), $project, ['monitor']));
    }

    private function createCoreTables(): void
    {
        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('source_product', 24);
            $table->string('source_entity', 100);
            $table->string('source_id', 191);
            $table->string('canonical_entity', 100)->nullable();
            $table->char('canonical_id', 26)->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamps();
        });

        Schema::connection('core')->create('project_resources', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('environment_id', 26)->nullable();
            $table->string('product', 24);
            $table->string('resource_type', 100);
            $table->string('resource_id', 191);
            $table->string('resource_public_id', 191)->nullable();
            $table->string('name')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('mapped_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function createCoreAccessTables(): void
    {
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26);
            $table->string('name');
            $table->string('slug');
            $table->string('status', 24)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 32);
            $table->string('status', 24);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('membership_id', 26);
            $table->string('product', 24);
            $table->string('role', 32);
            $table->string('status', 24);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 32);
            $table->string('status', 24);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_products', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->string('product', 24);
            $table->string('status', 24);
            $table->timestamps();
        });
    }

    private function createMonitorTables(): void
    {
        Schema::connection('monitor')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug');
            $table->string('plan')->default('free');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('user_workspace', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('member');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('framework')->nullable();
            $table->string('framework_version')->nullable();
            $table->string('accent')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('environments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('active');
            $table->unsignedBigInteger('event_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id');
            $table->string('name');
            $table->string('type')->default('http');
            $table->string('health')->default('unknown');
            $table->boolean('enabled')->default(true);
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedInteger('interval_minutes')->default(5);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('alert_rule_id')->nullable();
            $table->unsignedBigInteger('monitor_id')->nullable();
            $table->string('status');
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();
        });
    }

    private function createAnalyticsTables(): void
    {
        Schema::connection('analytics')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::connection('analytics')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        Schema::connection('analytics')->create('workspace_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('viewer');
            $table->timestamps();
        });
        Schema::connection('analytics')->create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->string('slug');
            $table->string('public_id')->nullable();
            $table->json('domains')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('verification_token')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->boolean('collection_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function platformUser(): PlatformUser
    {
        $user = new PlatformUser;
        $user->setAttribute('id', self::PLATFORM_USER_ID);
        $user->setAttribute('status', 'active');

        return $user;
    }

    private function project(): Project
    {
        $project = new Project;
        $project->setAttribute('id', self::PROJECT_ID);

        return $project;
    }

    private function addIdentity(string $product, string $sourceUserId): void
    {
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => '01J8AA00000000000000000010',
            'source_product' => $product,
            'source_entity' => 'user',
            'source_id' => $sourceUserId,
            'canonical_entity' => 'user',
            'canonical_id' => self::PLATFORM_USER_ID,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addProjectResource(string $product, string $resourceType, string $resourceId): void
    {
        DB::connection('core')->table('project_resources')->insert([
            'id' => '01J8AA00000000000000000011',
            'project_id' => self::PROJECT_ID,
            'product' => $product,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addMonitorWorkspaceAndApplication(int $memberId, int $workspaceId, int $applicationId): void
    {
        DB::connection('monitor')->table('users')->insert([
            'id' => $memberId,
            'name' => 'Monitor user',
            'email' => 'monitor@example.test',
            'password' => 'password-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('workspaces')->insert([
            'id' => $workspaceId,
            'owner_id' => $memberId,
            'name' => 'Monitor workspace',
            'slug' => 'monitor-workspace-'.$workspaceId,
            'plan' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('user_workspace')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $memberId,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('monitor')->table('applications')->insert([
            'id' => $applicationId,
            'workspace_id' => $workspaceId,
            'name' => 'Monitor application',
            'slug' => 'monitor-application-'.$applicationId,
            'framework' => 'Laravel',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addAnalyticsWorkspacesAndSite(int $memberId, int $firstWorkspaceId, int $secondWorkspaceId, int $siteId): void
    {
        DB::connection('analytics')->table('users')->insert([
            'id' => $memberId,
            'name' => 'Analytics user',
            'email' => 'analytics@example.test',
            'password' => 'password-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$firstWorkspaceId, $secondWorkspaceId] as $workspaceId) {
            DB::connection('analytics')->table('workspaces')->insert([
                'id' => $workspaceId,
                'name' => 'Analytics workspace '.$workspaceId,
                'slug' => 'analytics-workspace-'.$workspaceId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('analytics')->table('workspace_user')->insert([
                'workspace_id' => $workspaceId,
                'user_id' => $memberId,
                'role' => 'owner',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::connection('analytics')->table('sites')->insert([
            'id' => $siteId,
            'workspace_id' => $secondWorkspaceId,
            'name' => 'Analytics site',
            'slug' => 'analytics-site-'.$siteId,
            'public_id' => 'public-site-'.$siteId,
            'domains' => json_encode(['example.test'], JSON_THROW_ON_ERROR),
            'verification_token' => 'verification-token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
