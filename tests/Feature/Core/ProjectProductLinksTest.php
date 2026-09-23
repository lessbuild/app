<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectProductLink;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\ProjectProductLinkRegistry;
use App\Core\Services\ProjectProductLinks;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Analytics\Actions\Workspaces\EnsurePersonalWorkspace;
use App\Modules\Analytics\Services\Core\AnalyticsProjectLink;
use App\Modules\Analytics\Services\Core\AnalyticsResourceDestinationProvider;
use App\Modules\Analytics\Services\Core\AnalyticsResourceLinkProvider;
use App\Modules\Deployer\Services\Core\DeployerProjectLink;
use App\Modules\Deployer\Services\Core\DeployerProjectSetup;
use App\Modules\Deployer\Services\Core\DeployerResourceDestinationProvider;
use App\Modules\Deployer\Services\Core\DeployerResourceLinkProvider;
use App\Modules\Monitor\Models\Application as MonitorApplication;
use App\Modules\Monitor\Models\User as MonitorUser;
use App\Modules\Monitor\Services\Core\MonitorProjectLink;
use App\Modules\Monitor\Services\Core\MonitorProjectSetup;
use App\Modules\Monitor\Services\Core\MonitorProjectSummary;
use App\Modules\Monitor\Services\Core\MonitorResourceDestinationProvider;
use App\Modules\Monitor\Services\Core\MonitorResourceLinkProvider;
use App\Modules\Monitor\Services\CurrentWorkspace as MonitorCurrentWorkspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

        foreach (['telemetry_events', 'monitor_checks', 'incidents', 'alert_rules', 'monitors', 'environments', 'applications', 'user_workspace', 'workspaces', 'users'] as $table) {
            Schema::connection('monitor')->dropIfExists($table);
        }

        foreach ([
            'project_products',
            'workspace_product_access',
            'project_memberships',
            'workspace_memberships',
            'workspaces',
            'project_resources',
            'project_environments',
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

    public function test_monitor_resource_destination_is_keyed_by_the_core_mapping_id_and_checks_membership(): void
    {
        Route::get('/monitor/applications/{application}', static fn () => null)
            ->name('monitor.applications.show');
        Route::getRoutes()->refreshNameLookups();
        $this->addIdentity('monitor', '17');
        $this->addProjectResource('monitor', 'application', '31');
        $this->addMonitorWorkspaceAndApplication(memberId: 17, workspaceId: 50, applicationId: 31);
        $mapping = ProjectResource::query()->findOrFail('01J8AA00000000000000000011');

        $destinations = app(MonitorResourceDestinationProvider::class)
            ->destinations($this->platformUser(), collect([$mapping]));

        $this->assertSame(['01J8AA00000000000000000011'], array_keys($destinations));
        $this->assertSame(ProjectResourceDestinationState::Available, $destinations['01J8AA00000000000000000011']->state);
        $this->assertSame('/monitor/applications/31', parse_url($destinations['01J8AA00000000000000000011']->url, PHP_URL_PATH));

        DB::connection('monitor')->table('applications')->update(['deleted_at' => now()]);

        $this->assertSame(
            ProjectResourceDestinationState::Stale,
            app(MonitorResourceDestinationProvider::class)->destinations($this->platformUser(), collect([$mapping]))['01J8AA00000000000000000011']->state,
        );

        DB::connection('monitor')->table('applications')->update(['deleted_at' => null]);

        DB::connection('monitor')->table('user_workspace')->delete();

        $this->assertSame(
            ProjectResourceDestinationState::AccessChanged,
            app(MonitorResourceDestinationProvider::class)->destinations($this->platformUser(), collect([$mapping]))['01J8AA00000000000000000011']->state,
        );

        DB::connection('monitor')->table('applications')->delete();

        $this->assertSame(
            ProjectResourceDestinationState::Missing,
            app(MonitorResourceDestinationProvider::class)->destinations($this->platformUser(), collect([$mapping]))['01J8AA00000000000000000011']->state,
        );
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

    public function test_analytics_resource_destination_is_keyed_by_the_core_mapping_id_and_checks_membership(): void
    {
        Route::get('/analytics/dashboard', static fn () => null)->name('analytics.dashboard');
        Route::getRoutes()->refreshNameLookups();
        $this->addIdentity('analytics', '23');
        $this->addProjectResource('analytics', 'site', '71');
        $this->addAnalyticsWorkspacesAndSite(memberId: 23, firstWorkspaceId: 100, secondWorkspaceId: 200, siteId: 71);
        $mapping = ProjectResource::query()->findOrFail('01J8AA00000000000000000011');

        $destinations = app(AnalyticsResourceDestinationProvider::class)
            ->destinations($this->platformUser(), collect([$mapping]));

        $this->assertSame(['01J8AA00000000000000000011'], array_keys($destinations));
        $this->assertSame(ProjectResourceDestinationState::Available, $destinations['01J8AA00000000000000000011']->state);
        $this->assertSame('/analytics/dashboard', parse_url($destinations['01J8AA00000000000000000011']->url, PHP_URL_PATH));
        parse_str((string) parse_url($destinations['01J8AA00000000000000000011']->url, PHP_URL_QUERY), $destinationQuery);
        $this->assertSame('71', $destinationQuery['site']);

        DB::connection('analytics')->table('sites')->update(['deleted_at' => now()]);

        $this->assertSame(
            ProjectResourceDestinationState::Stale,
            app(AnalyticsResourceDestinationProvider::class)->destinations($this->platformUser(), collect([$mapping]))['01J8AA00000000000000000011']->state,
        );

        DB::connection('analytics')->table('workspace_user')->delete();

        $this->assertSame(
            ProjectResourceDestinationState::AccessChanged,
            app(AnalyticsResourceDestinationProvider::class)->destinations($this->platformUser(), collect([$mapping]))['01J8AA00000000000000000011']->state,
        );
    }

    public function test_deployer_resource_destination_is_keyed_by_the_core_mapping_id_and_checks_organization_access(): void
    {
        Route::get('/projects/{project}', static fn () => null)->name('projects.show');
        Route::getRoutes()->refreshNameLookups();
        $this->addIdentity('deployer', '17');
        $this->addProjectResource('deployer', 'project', '31');

        $schema = Schema::connection('deployer');
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('current_organization_id')->nullable();
        });
        $schema->create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
        });
        $schema->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('viewer');
        });
        $schema->create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
        });

        try {
            DB::connection('deployer')->table('users')->insert([
                'id' => 17,
                'name' => 'Deployer user',
                'current_organization_id' => 50,
            ]);
            DB::connection('deployer')->table('organizations')->insert([
                'id' => 50,
                'owner_id' => 999,
            ]);
            DB::connection('deployer')->table('projects')->insert([
                'id' => 31,
                'organization_id' => 50,
            ]);
            $mapping = ProjectResource::query()->findOrFail('01J8AA00000000000000000011');
            $provider = app(DeployerResourceDestinationProvider::class);

            $this->assertSame(
                ProjectResourceDestinationState::AccessChanged,
                $provider->destinations($this->platformUser(), collect([$mapping]))['01J8AA00000000000000000011']->state,
            );

            DB::connection('deployer')->table('organization_user')->insert([
                'organization_id' => 50,
                'user_id' => 17,
                'role' => 'viewer',
            ]);

            $destinations = $provider->destinations($this->platformUser(), collect([$mapping]));
            $this->assertSame(['01J8AA00000000000000000011'], array_keys($destinations));
            $this->assertSame(ProjectResourceDestinationState::Available, $destinations['01J8AA00000000000000000011']->state);
            $this->assertSame('/projects/31', parse_url($destinations['01J8AA00000000000000000011']->url, PHP_URL_PATH));

            DB::connection('deployer')->table('projects')->delete();

            $this->assertSame(
                ProjectResourceDestinationState::Missing,
                $provider->destinations($this->platformUser(), collect([$mapping]))['01J8AA00000000000000000011']->state,
            );
        } finally {
            foreach (['projects', 'organization_user', 'organizations', 'users'] as $table) {
                $schema->dropIfExists($table);
            }
        }
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

    public function test_monitor_environment_summary_only_reads_the_mapped_local_environment(): void
    {
        $this->addIdentity('monitor', '17');
        $this->addProjectResource('monitor', 'application', '31');
        $this->addMonitorWorkspaceAndApplication(memberId: 17, workspaceId: 50, applicationId: 31);
        $canonicalIds = [];

        foreach ([[41, 'Production', 'production', 'up'], [42, 'Staging', 'staging', 'down']] as [$sourceId, $name, $type, $health]) {
            $canonicalId = (string) Str::ulid();
            $canonicalIds[$type] = $canonicalId;
            DB::connection('core')->table('project_environments')->insert([
                'id' => $canonicalId,
                'project_id' => self::PROJECT_ID,
                'name' => $name,
                'slug' => $type,
                'environment_type' => $type,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('core')->table('project_resources')->insert([
                'id' => (string) Str::ulid(),
                'project_id' => self::PROJECT_ID,
                'environment_id' => $canonicalId,
                'product' => 'monitor',
                'resource_type' => 'environment',
                'resource_id' => (string) $sourceId,
                'name' => $name,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('monitor')->table('environments')->insert([
                'id' => $sourceId,
                'application_id' => 31,
                'name' => $name,
                'slug' => $type,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('monitor')->table('monitors')->insert([
                'id' => $sourceId + 100,
                'environment_id' => $sourceId,
                'name' => $name.' check',
                'type' => 'http',
                'health' => $health,
                'enabled' => true,
                'checked_at' => now(),
                'interval_minutes' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $summaryProvider = new MonitorProjectSummary(app(MonitorProjectLink::class));
        $staging = $summaryProvider->summarizeForEnvironment(
            $this->platformUser(),
            $this->project(),
            ProjectEnvironment::query()->findOrFail($canonicalIds['staging']),
        );
        $production = $summaryProvider->summarizeForEnvironment(
            $this->platformUser(),
            $this->project(),
            ProjectEnvironment::query()->findOrFail($canonicalIds['production']),
        );

        $this->assertSame(ProjectProductSnapshotState::Attention, $staging?->state);
        $this->assertSame('0 open incidents · 0 checks up · 1 checks down · 0 unknown · 0 paused', $staging?->detail);
        $this->assertSame(ProjectProductSnapshotState::Current, $production?->state);
        $this->assertSame('0 open incidents · 1 checks up · 0 checks down · 0 unknown · 0 paused', $production?->detail);
    }

    public function test_monitor_setup_progress_comes_from_authorized_application_data(): void
    {
        $this->addIdentity('monitor', '17');
        $this->addProjectResource('monitor', 'application', '31');
        $this->addMonitorWorkspaceAndApplication(memberId: 17, workspaceId: 50, applicationId: 31);
        foreach ([
            [41, 'Production', 'production', '01J8AA00000000000000000030', '01J8AA00000000000000000012'],
            [42, 'Staging', 'staging', '01J8AA00000000000000000031', '01J8AA00000000000000000013'],
        ] as [$sourceEnvironmentId, $name, $type, $canonicalEnvironmentId, $resourceId]) {
            DB::connection('monitor')->table('environments')->insert([
                'id' => $sourceEnvironmentId,
                'application_id' => 31,
                'name' => $name,
                'slug' => $type,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('core')->table('project_environments')->insert([
                'id' => $canonicalEnvironmentId,
                'project_id' => self::PROJECT_ID,
                'name' => $name,
                'slug' => $type,
                'environment_type' => $type,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('core')->table('project_resources')->insert([
                'id' => $resourceId,
                'project_id' => self::PROJECT_ID,
                'environment_id' => $canonicalEnvironmentId,
                'product' => 'monitor',
                'resource_type' => 'environment',
                'resource_id' => (string) $sourceEnvironmentId,
                'name' => $name,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $setup = new MonitorProjectSetup(app(MonitorProjectLink::class));
        $steps = $setup->steps($this->platformUser(), $this->project());

        $this->assertSame(ProjectSetupStepState::Complete, $steps[0]->state);
        $this->assertCount(3, $steps);
        $production = collect($steps)->firstWhere('contextName', 'Production');
        $staging = collect($steps)->firstWhere('contextName', 'Staging');
        $this->assertSame(ProjectSetupStepState::NeedsAction, $production->state);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $staging->state);

        DB::connection('monitor')->table('telemetry_events')->insert([
            'id' => 61,
            'environment_id' => 41,
            'type' => 'log',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $steps = $setup->steps($this->platformUser(), $this->project());

        $production = collect($steps)->firstWhere('contextName', 'Production');
        $staging = collect($steps)->firstWhere('contextName', 'Staging');
        $this->assertSame(ProjectSetupStepState::Complete, $production->state);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $staging->state);
    }

    public function test_monitor_resource_candidates_require_mapped_workspace_membership_and_exclude_linked_apps(): void
    {
        $this->addIdentity('monitor', '17');
        $this->addMonitorWorkspaceAndApplication(memberId: 17, workspaceId: 50, applicationId: 31);
        DB::connection('monitor')->table('environments')->insert([
            'id' => 42,
            'application_id' => 31,
            'name' => 'Production',
            'slug' => 'production',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $provider = new MonitorResourceLinkProvider(app(LegacyIdentityResolver::class));
        $candidates = $provider->candidates($this->platformUser());

        $this->assertCount(2, $candidates);
        $this->assertSame('31', $candidates[0]->id);
        $this->assertSame('application:31', $candidates[0]->selectionKey());
        $this->assertSame('Monitor workspace', $candidates[0]->detail);
        $this->assertSame('environment:42', $candidates[1]->selectionKey());
        $this->assertSame('Production', $candidates[1]->name);

        $this->addProjectResource('monitor', 'application', '31');

        $this->assertNull($provider->candidate($this->platformUser(), 'application:31'));
        $this->assertNotNull($provider->candidate($this->platformUser(), 'environment:42'));
    }

    public function test_deployer_resource_candidates_include_authorized_projects_and_environments_without_type_collisions(): void
    {
        $this->addIdentity('deployer', '17');

        $schema = Schema::connection('deployer');
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('current_organization_id')->nullable();
        });
        $schema->create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('owner_id');
        });
        $schema->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
        });
        $schema->create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
        });
        $schema->create('environments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('name');
        });

        try {
            DB::connection('deployer')->table('users')->insert([
                'id' => 17,
                'current_organization_id' => 50,
            ]);
            DB::connection('deployer')->table('organizations')->insert([
                'id' => 50,
                'name' => 'Deployer organization',
                'owner_id' => 17,
            ]);
            DB::connection('deployer')->table('projects')->insert([
                'id' => 31,
                'organization_id' => 50,
                'name' => 'Storefront',
            ]);
            DB::connection('deployer')->table('environments')->insert([
                'id' => 42,
                'project_id' => 31,
                'name' => 'Production',
            ]);

            $provider = new DeployerResourceLinkProvider(app(LegacyIdentityResolver::class));
            $candidates = $provider->candidates($this->platformUser());

            $this->assertCount(2, $candidates);
            $this->assertSame('project:31', $candidates[0]->selectionKey());
            $this->assertSame('environment:42', $candidates[1]->selectionKey());
            $this->assertSame('Storefront', $candidates[1]->detail);
            $this->assertNotNull($provider->candidate($this->platformUser(), 'environment:42'));

            $this->addProjectResource('deployer', 'project', '31');
            $this->assertNull($provider->candidate($this->platformUser(), 'project:31'));
            $this->assertNotNull($provider->candidate($this->platformUser(), 'environment:42'));

            DB::connection('core')->table('project_resources')->insert([
                'id' => '01J8AA00000000000000000012',
                'project_id' => self::PROJECT_ID,
                'product' => 'deployer',
                'resource_type' => 'environment',
                'resource_id' => '42',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertNull($provider->candidate($this->platformUser(), 'environment:42'));
        } finally {
            foreach (['environments', 'projects', 'organization_user', 'organizations', 'users'] as $table) {
                $schema->dropIfExists($table);
            }
        }
    }

    public function test_analytics_resource_candidates_require_mapped_workspace_membership_and_exclude_linked_sites(): void
    {
        $this->addIdentity('analytics', '23');
        $this->addAnalyticsWorkspacesAndSite(memberId: 23, firstWorkspaceId: 100, secondWorkspaceId: 200, siteId: 71);

        $provider = new AnalyticsResourceLinkProvider(app(LegacyIdentityResolver::class));
        $candidates = $provider->candidates($this->platformUser());

        $this->assertCount(1, $candidates);
        $this->assertSame('71', $candidates[0]->id);
        $this->assertSame('Analytics workspace 200', $candidates[0]->detail);

        $this->addProjectResource('analytics', 'site', '71');

        $this->assertNull($provider->candidate($this->platformUser(), 'site:71'));
    }

    public function test_deployer_setup_resumes_from_the_existing_project_list_when_not_yet_linked(): void
    {
        Route::get('/deployer/projects', static fn () => null)->name('projects.index');
        Route::getRoutes()->refreshNameLookups();

        $steps = (new DeployerProjectSetup(app(DeployerProjectLink::class)))
            ->steps($this->platformUser(), $this->project());

        $this->assertCount(3, $steps);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $steps[0]->state);
        $this->assertSame('Connect a Deployer project', $steps[0]->title);
        $this->assertNotNull($steps[0]->url);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $steps[1]->state);
        $this->assertSame(ProjectSetupStepState::NeedsAction, $steps[2]->state);
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

        $access = app(WorkspaceProjectAccess::class);
        $this->assertTrue($access->canLinkProductResource($this->platformUser(), $project, 'monitor'));
        $this->assertTrue($access->canAccessProductResource($this->platformUser(), $project, 'monitor'));

        DB::connection('core')->table('project_products')->where('project_id', self::PROJECT_ID)->delete();

        $this->assertTrue($access->canLinkProductResource($this->platformUser(), $project, 'monitor'));
        $this->assertFalse($access->canAccessProductResource($this->platformUser(), $project, 'monitor'));

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

        Schema::connection('core')->create('project_environments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->string('name');
            $table->string('slug');
            $table->string('environment_type');
            $table->string('status');
            $table->json('metadata')->nullable();
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
            $table->timestamp('expires_at')->nullable();
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
        Schema::connection('monitor')->create('monitor_checks', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('monitor_id');
            $table->timestamp('finished_at')->nullable();
        });
        Schema::connection('monitor')->create('telemetry_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id');
            $table->string('type');
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
