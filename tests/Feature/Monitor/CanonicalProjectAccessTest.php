<?php

namespace Tests\Feature\Monitor;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Projects\ManageCanonicalProjectMembership;
use App\Modules\Monitor\Http\Controllers\EventController;
use App\Modules\Monitor\Http\Controllers\TraceEventController;
use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Core\MonitorWorkspaceSearchProvider;
use App\Modules\Monitor\Services\CreateIngestToken;
use App\Modules\Monitor\Services\ExportWorkspaceData;
use App\Modules\Monitor\Services\Telemetry\CollectionHealthSummary;
use App\Modules\Monitor\Services\Telemetry\DashboardMetrics;
use App\Modules\Monitor\Services\Telemetry\ServiceDependencyMap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Authored for the deferred plan-wide regression run. */
final class CanonicalProjectAccessTest extends TestCase
{
    private PlatformUser $owner;

    private PlatformUser $principal;

    private CoreWorkspace $coreWorkspace;

    private WorkspaceMembership $membership;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    private Application $restricted;

    private Application $allowed;

    private Environment $restrictedEnvironment;

    private Environment $allowedEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['core', 'monitor'] as $connection) {
            config(["database.connections.{$connection}.database" => ':memory:']);
            DB::purge($connection);
            $this->assertSame(0, Artisan::call('platform:migrate', ['module' => $connection]));
        }
        config(['platform.products.monitor.auth_authority' => 'core']);
        foreach (['Workspace', 'Application', 'Environment', 'Monitor', 'AlertRule', 'Incident', 'AlertDelivery'] as $model) {
            Gate::policy('App\\Modules\\Monitor\\Models\\'.$model, 'App\\Modules\\Monitor\\Policies\\'.$model.'Policy');
        }

        $this->owner = $this->platformUser('owner');
        $this->principal = $this->platformUser('member');
        $this->coreWorkspace = CoreWorkspace::query()->create([
            'owner_user_id' => $this->owner->getKey(), 'name' => 'Studio', 'slug' => 'studio', 'status' => 'active',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $this->coreWorkspace->getKey(), 'user_id' => $this->owner->getKey(), 'role' => 'owner', 'status' => 'active',
        ]);
        $this->membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->coreWorkspace->getKey(), 'user_id' => $this->principal->getKey(), 'role' => 'member', 'status' => 'active',
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $this->membership->getKey(), 'product' => 'monitor', 'role' => 'admin', 'status' => 'active',
        ]);
        $this->user = User::query()->forceCreate([
            'name' => 'Monitor admin', 'email' => 'monitor@example.test', 'password' => 'unused', 'email_verified_at' => now(),
        ]);
        $this->workspace = Workspace::query()->forceCreate([
            'owner_id' => $this->user->getKey(), 'name' => 'Studio', 'slug' => 'studio', 'plan' => 'free',
        ]);
        $this->workspace->members()->attach($this->user, ['role' => 'admin']);
        $this->identity('user', $this->user->getKey(), 'user', $this->principal->getKey());
        $this->identity('workspace', $this->workspace->getKey(), 'workspace', $this->coreWorkspace->getKey());
        [$this->project, $this->restricted, $this->restrictedEnvironment] = $this->application('restricted');
        [, $this->allowed, $this->allowedEnvironment] = $this->application('allowed');
    }

    public function test_core_revocation_removes_native_collections_and_direct_permissions_without_deleting_product_data(): void
    {
        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->restricted));
        $this->revoke();

        $this->assertFalse(Gate::forUser($this->user)->allows('view', $this->restricted));
        $this->assertFalse(Gate::forUser($this->user)->allows('update', $this->restrictedEnvironment));
        $this->assertSame([$this->allowed->id], $this->workspace->applications()->visibleTo($this->user, $this->workspace)->pluck('id')->all());
        $this->assertSame([$this->allowedEnvironment->id], Environment::forWorkspace($this->workspace)->visibleTo($this->user, $this->workspace)->pluck('id')->all());
        $this->assertSame(2, $this->workspace->applications()->count());
        $this->assertSame(2, Environment::forWorkspace($this->workspace)->count());
        $this->assertSame('admin', $this->workspace->roleFor($this->user));
        $this->assertTrue(Gate::forUser($this->user)->allows('update', $this->allowedEnvironment));

        $this->workspace->members()->updateExistingPivot($this->user->id, ['role' => 'viewer']);
        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->allowedEnvironment));
        $this->assertFalse(Gate::forUser($this->user)->allows('update', $this->allowedEnvironment));
    }

    public function test_unmapped_sources_and_legacy_authority_keep_native_access_but_inactive_mappings_do_not(): void
    {
        $unmapped = Application::query()->forceCreate(['workspace_id' => $this->workspace->id, 'name' => 'Unmapped', 'slug' => 'unmapped', 'accent' => 'sky', 'framework' => 'Laravel']);
        $this->revoke();
        $this->assertTrue(Gate::forUser($this->user)->allows('view', $unmapped));
        ProjectResource::query()->where('resource_type', 'application')->where('resource_id', (string) $this->allowed->id)->update(['status' => 'inactive']);
        $this->assertFalse(Gate::forUser($this->user)->allows('view', $this->allowed));
        $this->assertSame([$unmapped->id], Application::query()->visibleTo($this->user, $this->workspace)->pluck('id')->all());

        config(['platform.products.monitor.auth_authority' => 'legacy']);
        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->restricted));
        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->allowed));
        $this->assertSame(3, Application::query()->visibleTo($this->user, $this->workspace)->count());
    }

    public function test_paused_environments_remain_visible_and_resumable(): void
    {
        $canonical = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'name' => 'Production', 'slug' => 'production', 'status' => 'paused',
        ]);
        ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'environment_id' => $canonical->getKey(), 'product' => 'monitor',
            'resource_type' => 'environment', 'resource_id' => (string) $this->restrictedEnvironment->id, 'status' => 'paused',
        ]);
        $this->restrictedEnvironment->update(['status' => 'paused']);

        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->restrictedEnvironment));
        $this->assertTrue(Gate::forUser($this->user)->allows('update', $this->restrictedEnvironment));
        $this->assertTrue(Environment::query()->visibleTo($this->user, $this->workspace)->whereKey($this->restrictedEnvironment->id)->exists());
        $this->revoke();
        $this->assertFalse(Gate::forUser($this->user)->allows('view', $this->restrictedEnvironment));
    }

    public function test_environment_restrictions_do_not_block_authorized_siblings_but_prevent_application_wide_changes(): void
    {
        $canonical = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'name' => 'Production', 'slug' => 'production', 'status' => 'active',
        ]);
        ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'environment_id' => $canonical->getKey(), 'product' => 'monitor',
            'resource_type' => 'environment', 'resource_id' => (string) $this->allowedEnvironment->id, 'status' => 'active',
        ]);
        $sibling = Environment::query()->forceCreate([
            'application_id' => $this->allowed->id, 'name' => 'Staging', 'slug' => 'staging', 'status' => 'active',
        ]);
        $this->revoke();

        $this->assertTrue(Gate::forUser($this->user)->allows('view', $this->allowed));
        $this->assertFalse(Gate::forUser($this->user)->allows('view', $this->allowedEnvironment));
        $this->assertFalse(Gate::forUser($this->user)->allows('delete', $this->allowed));
        $this->assertTrue(Gate::forUser($this->user)->allows('update', $sibling));
        $this->assertSame([$sibling->id], $this->allowed->environments()->visibleTo($this->user, $this->workspace)->pluck('id')->all());
    }

    public function test_status_management_and_incident_deliveries_require_source_access(): void
    {
        $rule = AlertRule::factory()->create(['environment_id' => $this->restrictedEnvironment->id]);
        $incident = Incident::factory()->create(['alert_rule_id' => $rule->id]);
        $monitor = Monitor::factory()->create(['environment_id' => $this->restrictedEnvironment->id]);
        $page = StatusPage::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Private status', 'slug' => 'private-status', 'published' => false]);
        $page->components()->create(['monitor_id' => $monitor->id, 'label' => 'Private monitor', 'position' => 0]);
        $delivery = (new AlertDelivery)->forceFill(['workspace_id' => $this->workspace->id, 'incident_id' => $incident->id])->setRelation('workspace', $this->workspace)->setRelation('incident', $incident);
        $testDelivery = (new AlertDelivery)->forceFill(['workspace_id' => $this->workspace->id, 'incident_id' => null])->setRelation('workspace', $this->workspace);
        $this->revoke();

        $this->assertFalse($this->workspace->statusPages()->visibleTo($this->user, $this->workspace)->whereKey($page->id)->exists());
        $this->assertFalse(Gate::forUser($this->user)->allows('view', $delivery));
        $this->assertFalse(Gate::forUser($this->user)->allows('update', $delivery));
        $this->assertTrue(Gate::forUser($this->user)->allows('view', $testDelivery));
    }

    public function test_incident_queries_and_core_search_check_every_populated_source(): void
    {
        $rule = AlertRule::factory()->create(['environment_id' => $this->restrictedEnvironment->id]);
        $monitor = Monitor::factory()->create(['environment_id' => $this->allowedEnvironment->id]);
        $mixed = Incident::factory()->create(['alert_rule_id' => $rule->id, 'monitor_id' => $monitor->id]);
        $allowedRule = AlertRule::factory()->create(['environment_id' => $this->allowedEnvironment->id]);
        $visible = Incident::factory()->create(['alert_rule_id' => $allowedRule->id]);
        $this->revoke();

        $this->assertFalse(Gate::forUser($this->user)->allows('view', $mixed));
        $this->assertSame([$visible->id], Incident::forWorkspace($this->workspace)->visibleTo($this->user, $this->workspace)->pluck('id')->all());
        Route::get('/monitor/incidents/{incident}', fn () => response(''))->name('monitor.incidents.show');
        $results = app(MonitorWorkspaceSearchProvider::class)->search($this->principal, $this->coreWorkspace, 'open');
        $this->assertCount(1, $results);
        $this->assertStringContainsString('/monitor/incidents/'.$visible->id, $results[0]->url);
    }

    public function test_project_revocation_filters_event_aggregates_and_reports(): void
    {
        $this->event($this->restrictedEnvironment, 'private-service');
        $this->event($this->allowedEnvironment, 'public-service');
        $this->revoke();

        $this->assertSame(1, app(DashboardMetrics::class)->forWorkspace($this->workspace, '24h', $this->user)['eventCount']);
        $this->assertSame(1, app(CollectionHealthSummary::class)->forWorkspace($this->workspace, $this->user)['total']);
        $map = app(ServiceDependencyMap::class)->forWorkspace($this->workspace, '24h', principal: $this->user);
        $this->assertSame(1, $map['records']);
        $this->assertSame(['public-service'], array_column($map['services'], 'name'));
        $this->assertSame(2, TelemetryEvent::forWorkspace($this->workspace)->count());
    }

    public function test_event_and_trace_detail_routes_reject_a_revoked_project(): void
    {
        $event = $this->event($this->restrictedEnvironment, 'private-service');
        $this->revoke();
        Route::middleware(['web', function (Request $request, $next) {
            $request->attributes->set('platform_user', $this->principal);

            return $next($request);
        }])->group(function (): void {
            Route::get('/monitor-access/events/{event}', [EventController::class, 'show']);
            Route::get('/monitor-access/traces/{trace}/{event}', [TraceEventController::class, 'show']);
        });

        $this->actingAs($this->user)->get('/monitor-access/events/'.$event->id)->assertNotFound();
        $this->get('/monitor-access/traces/'.$event->trace_id.'/'.$event->id)->assertNotFound();
    }

    public function test_token_issuance_rechecks_the_actor_and_leaves_revoked_data_intact(): void
    {
        $this->revoke();
        try {
            app(CreateIngestToken::class)->create($this->restrictedEnvironment, $this->user, 'Denied token');
            $this->fail('A revoked project must not issue new ingestion credentials.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $this->restrictedEnvironment->ingestTokens()->count());
            $this->assertDatabaseHas('environments', ['id' => $this->restrictedEnvironment->id], 'monitor');
        }
    }

    public function test_workspace_export_omits_revoked_applications_environments_and_telemetry(): void
    {
        $this->event($this->restrictedEnvironment, 'private-service');
        $this->event($this->allowedEnvironment, 'public-service');
        $this->revoke();
        $output = fopen('php://temp', 'w+');
        try {
            app(ExportWorkspaceData::class)->write($this->workspace, $output, $this->user);
            rewind($output);
            $records = collect(explode("\n", trim(stream_get_contents($output))))->map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR));
            $this->assertSame([$this->allowed->id], $records->where('type', 'application')->pluck('data.id')->values()->all());
            $this->assertSame([$this->allowedEnvironment->id], $records->where('type', 'environment')->pluck('data.id')->values()->all());
            $this->assertSame(['public-service'], $records->where('type', 'telemetry_event')->pluck('data.service')->values()->all());
        } finally {
            fclose($output);
        }
    }

    private function revoke(): void
    {
        app(ManageCanonicalProjectMembership::class)->revoke($this->owner, $this->coreWorkspace, $this->project, $this->membership->getKey());
    }

    private function platformUser(string $name): PlatformUser
    {
        return PlatformUser::query()->create([
            'name' => $name, 'email' => $name.'@example.test', 'email_normalized' => $name.'@example.test', 'password' => 'unused', 'status' => 'active',
        ]);
    }

    private function identity(string $entity, int $id, string $canonicalEntity, string $canonicalId): void
    {
        LegacyIdentityMap::query()->create([
            'source_product' => 'monitor', 'source_entity' => $entity, 'source_id' => (string) $id,
            'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId, 'status' => 'reconciled',
        ]);
    }

    /** @return array{Project, Application, Environment} */
    private function application(string $name): array
    {
        $project = Project::query()->create([
            'workspace_id' => $this->coreWorkspace->getKey(), 'name' => $name, 'slug' => $name, 'status' => 'active',
        ]);
        ProjectMembership::query()->create(['project_id' => $project->getKey(), 'user_id' => $this->principal->getKey(), 'role' => 'member', 'status' => 'active']);
        ProjectProduct::query()->create(['project_id' => $project->getKey(), 'product' => 'monitor', 'status' => 'active']);
        $application = Application::query()->forceCreate(['workspace_id' => $this->workspace->id, 'name' => $name, 'slug' => $name, 'accent' => 'sky', 'framework' => 'Laravel']);
        $environment = Environment::query()->forceCreate(['application_id' => $application->id, 'name' => 'Production', 'slug' => 'production', 'status' => 'active']);
        ProjectResource::query()->create([
            'project_id' => $project->getKey(), 'product' => 'monitor', 'resource_type' => 'application', 'resource_id' => (string) $application->id, 'status' => 'active',
        ]);

        return [$project, $application, $environment];
    }

    private function event(Environment $environment, string $service): TelemetryEvent
    {
        return TelemetryEvent::query()->forceCreate([
            'environment_id' => $environment->id, 'dedupe_key' => hash('sha256', $service), 'trace_id' => $service,
            'span_id' => 'span-'.$service, 'type' => 'request', 'severity' => 'info', 'name' => 'GET /health',
            'service' => $service, 'duration_ms' => 10, 'status_code' => 200, 'occurred_at' => now()->subMinute(), 'payload' => [], 'attributes' => [],
        ]);
    }
}
