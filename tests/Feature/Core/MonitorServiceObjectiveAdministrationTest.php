<?php

namespace Tests\Feature\Core;

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
use App\Core\Services\WorkspaceMonitorAdministrationRegistry;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Models\User as MonitorUser;
use App\Modules\Monitor\Models\Workspace as MonitorWorkspace;
use App\Modules\Monitor\Policies\ApplicationPolicy;
use App\Modules\Monitor\Policies\EnvironmentPolicy;
use App\Modules\Monitor\Policies\WorkspacePolicy;
use App\Modules\Monitor\Services\Core\MonitorServiceObjectiveAdministrationProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Regression coverage authored for Core Monitor service objectives; intentionally not executed here. */
final class MonitorServiceObjectiveAdministrationTest extends TestCase
{
    private PlatformUser $actor;

    private CoreWorkspace $workspace;

    private WorkspaceMembership $membership;

    private WorkspaceProductAccess $monitorGrant;

    private Project $project;

    private MonitorUser $monitorActor;

    private MonitorWorkspace $monitorWorkspace;

    private Application $application;

    private Environment $environment;

    private ProjectEnvironment $canonicalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.core.database' => ':memory:',
            'database.connections.monitor.database' => ':memory:',
            'platform.products.monitor.enabled' => true,
            'platform.products.monitor.auth_authority' => 'core',
            'billing.enforce_entitlements' => false,
        ]);
        foreach (['core', 'monitor'] as $connection) {
            DB::purge($connection);
            $this->assertSame(0, Artisan::call('platform:migrate', ['module' => $connection]));
        }

        Gate::policy(MonitorWorkspace::class, WorkspacePolicy::class);
        Gate::policy(Application::class, ApplicationPolicy::class);
        Gate::policy(Environment::class, EnvironmentPolicy::class);

        $this->actor = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(), 'name' => 'Core owner', 'email' => 'monitor-slo-owner@example.test',
            'email_normalized' => 'monitor-slo-owner@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $this->workspace = CoreWorkspace::query()->forceCreate([
            'id' => (string) Str::ulid(), 'owner_user_id' => $this->actor->getKey(),
            'name' => 'Core workspace', 'slug' => 'monitor-slo-core', 'status' => 'active',
        ]);
        $this->membership = WorkspaceMembership::query()->forceCreate([
            'id' => (string) Str::ulid(), 'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->actor->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->monitorGrant = WorkspaceProductAccess::query()->forceCreate([
            'id' => (string) Str::ulid(), 'membership_id' => $this->membership->getKey(), 'product' => 'monitor',
            'role' => 'owner', 'status' => 'active', 'granted_at' => now(),
        ]);
        $this->project = Project::query()->forceCreate([
            'id' => (string) Str::ulid(), 'workspace_id' => $this->workspace->getKey(), 'created_by_user_id' => $this->actor->getKey(),
            'name' => 'Mapped service', 'slug' => 'mapped-service', 'status' => 'active',
        ]);
        ProjectMembership::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'user_id' => $this->actor->getKey(),
            'role' => 'owner', 'status' => 'active', 'granted_at' => now(),
        ]);
        ProjectProduct::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'product' => 'monitor', 'status' => 'active',
        ]);

        $this->monitorActor = MonitorUser::query()->forceCreate([
            'name' => 'Monitor owner', 'email' => 'native-monitor-slo@example.test', 'password' => 'hashed', 'email_verified_at' => now(),
        ]);
        $this->monitorWorkspace = MonitorWorkspace::query()->forceCreate([
            'owner_id' => $this->monitorActor->getKey(), 'name' => 'Monitor workspace', 'slug' => 'monitor-slo-source', 'plan' => 'free',
        ]);
        $this->monitorWorkspace->members()->attach($this->monitorActor, ['role' => 'owner']);
        $this->application = Application::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'name' => 'Public API', 'slug' => 'public-api',
            'framework' => 'Laravel', 'framework_version' => '12', 'accent' => 'violet',
        ]);
        $this->environment = Environment::query()->forceCreate([
            'application_id' => $this->application->getKey(), 'name' => 'Production', 'slug' => 'production', 'status' => 'active',
        ]);
        $this->canonicalEnvironment = $this->mapEnvironment($this->environment, 'Production', 'production');

        $this->map('user', (string) $this->monitorActor->getKey(), 'user', (string) $this->actor->getKey());
        $this->map('workspace', (string) $this->monitorWorkspace->getKey(), 'workspace', (string) $this->workspace->getKey());
        $this->map('application', (string) $this->application->getKey(), 'project', (string) $this->project->getKey());
        ProjectResource::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'product' => 'monitor', 'resource_type' => 'application',
            'resource_id' => (string) $this->application->getKey(), 'name' => 'Public API', 'status' => 'active', 'mapped_at' => now(),
        ]);
        ProjectResource::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'environment_id' => $this->canonicalEnvironment->getKey(),
            'product' => 'monitor', 'resource_type' => 'environment', 'resource_id' => (string) $this->environment->getKey(),
            'name' => 'Production', 'status' => 'active', 'mapped_at' => now(),
        ]);
        app(WorkspaceMonitorAdministrationRegistry::class)->registerServiceObjectives(app(MonitorServiceObjectiveAdministrationProvider::class));
    }

    public function test_core_form_creates_updates_and_archives_through_monitor_native_service(): void
    {
        $provider = app(MonitorServiceObjectiveAdministrationProvider::class);
        $snapshot = $provider->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $this->assertSame(1, count($snapshot->environments));

        $environmentReference = $snapshot->environments[0]['reference'];
        $this->actingAs($this->actor, 'platform')->get(route('core.workspace.monitor.service-objectives', $this->workspace))
            ->assertOk()->assertSee('Service objectives')->assertSee('Create a service objective');
        $this->actingAs($this->actor, 'platform')->post(
            route('core.workspace.monitor.service-objectives.store', $this->workspace),
            $this->validObjective($environmentReference, ['service' => 'api?token=slo-secret']),
        )->assertRedirect(route('core.workspace.monitor.service-objectives', $this->workspace));

        $objective = ServiceLevelObjective::query()->where('environment_id', $this->environment->getKey())->firstOrFail();
        $this->assertSame('api?token=[REDACTED]', $objective->service);
        $this->assertSame((string) $this->environment->getKey(), (string) $objective->environment_id);
        $snapshot = $provider->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $this->assertCount(1, $snapshot->items->items());
        $item = $snapshot->items->items()[0];
        $this->assertArrayNotHasKey('id', $item);
        $this->assertArrayNotHasKey('report', $item);
        $this->assertArrayNotHasKey('export', $item);
        $this->assertSame('api?token=[REDACTED]', $item['service']);

        $this->actingAs($this->actor, 'platform')->patch(
            route('core.workspace.monitor.service-objectives.update', $this->workspace),
            $this->validObjective($environmentReference, [
                'objective_reference' => $item['reference'], 'version' => $item['version'],
                'name' => 'Production availability target', 'target' => '99.950',
            ]),
        )->assertRedirect(route('core.workspace.monitor.service-objectives', $this->workspace));
        $this->assertSame('Production availability target', $objective->fresh()->name);
        $this->assertSame(99.95, (float) $objective->fresh()->target);

        $updated = $provider->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($updated);
        $updatedItem = $updated->items->items()[0];
        $this->actingAs($this->actor, 'platform')->delete(
            route('core.workspace.monitor.service-objectives.archive', $this->workspace),
            ['objective_reference' => $updatedItem['reference'], 'version' => $updatedItem['version']],
        )->assertSessionHasErrors('confirm_archive');
        $this->assertNull($objective->fresh()->deleted_at);

        $this->actingAs($this->actor, 'platform')->delete(
            route('core.workspace.monitor.service-objectives.archive', $this->workspace),
            ['objective_reference' => $updatedItem['reference'], 'version' => $updatedItem['version'], 'confirm_archive' => '1'],
        )->assertRedirect(route('core.workspace.monitor.service-objectives', $this->workspace));
        $this->assertNotNull(ServiceLevelObjective::withTrashed()->findOrFail($objective->getKey())->deleted_at);

        $provider->saveObjective($this->actor, $this->workspace, null, $this->validObjective($environmentReference, [
            'name' => 'Latency objective', 'indicator' => 'latency', 'latency_threshold_ms' => '250',
            'status_min' => 'not-used-for-latency', 'status_max' => 'not-used-for-latency',
        ]));
        $latencyObjective = ServiceLevelObjective::query()->where('name', 'Latency objective')->firstOrFail();
        $this->assertSame('latency', $latencyObjective->indicator);
        $this->assertSame(250.0, (float) $latencyObjective->latency_threshold_ms);
        $this->assertSame(200, (int) $latencyObjective->status_min);
        $this->assertSame(399, (int) $latencyObjective->status_max);
    }

    public function test_snapshot_requires_exact_mapped_environment_and_current_core_project_access(): void
    {
        $provider = app(MonitorServiceObjectiveAdministrationProvider::class);
        $unmappedApplication = Application::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'name' => 'Unmapped API', 'slug' => 'unmapped-api',
            'framework' => 'Node.js', 'framework_version' => null, 'accent' => 'sky',
        ]);
        $unmappedEnvironment = Environment::query()->forceCreate([
            'application_id' => $unmappedApplication->getKey(), 'name' => 'Preview', 'slug' => 'preview', 'status' => 'active',
        ]);
        ServiceLevelObjective::query()->forceCreate([
            'environment_id' => $unmappedEnvironment->getKey(), 'name' => 'Unmapped objective', 'indicator' => 'availability',
            'target' => 99.9, 'window_days' => 30, 'latency_threshold_ms' => null, 'status_min' => 200, 'status_max' => 399, 'enabled' => true,
        ]);
        ServiceLevelObjective::query()->forceCreate([
            'environment_id' => $this->environment->getKey(), 'name' => 'Mapped objective', 'indicator' => 'availability',
            'target' => 99.9, 'window_days' => 30, 'latency_threshold_ms' => null, 'status_min' => 200, 'status_max' => 399, 'enabled' => true,
        ]);

        $snapshot = $provider->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $this->assertSame(1, $snapshot->items->total());
        $this->assertSame('Mapped objective', $snapshot->items->items()[0]['name']);
        $this->assertStringNotContainsString('Unmapped objective', json_encode($snapshot->items->items(), JSON_THROW_ON_ERROR));

        $environmentReference = $snapshot->environments[0]['reference'];
        ProjectMembership::query()->where('project_id', $this->project->getKey())->where('user_id', $this->actor->getKey())
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $denied = $provider->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($denied);
        $this->assertSame(0, $denied->items->total());
        $this->assertSame([], $denied->environments);

        $this->assertHttpStatus(404, fn () => $provider->saveObjective(
            $this->actor, $this->workspace, null, $this->validObjective($environmentReference),
        ));
        $this->assertDatabaseCount('service_level_objectives', 2, 'monitor');
    }

    public function test_inactive_application_resource_claim_from_another_project_makes_project_binding_ambiguous(): void
    {
        $this->nativeObjective($this->environment, 'Mapped objective');
        $foreignProject = Project::query()->forceCreate([
            'id' => (string) Str::ulid(), 'workspace_id' => $this->workspace->getKey(), 'created_by_user_id' => $this->actor->getKey(),
            'name' => 'Foreign project', 'slug' => 'foreign-monitor-project', 'status' => 'archived', 'archived_at' => now(),
        ]);
        $foreignApplication = Application::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'name' => 'Foreign mapped service', 'slug' => 'foreign-mapped-service',
            'framework' => 'Node.js', 'framework_version' => null, 'accent' => 'sky',
        ]);
        $this->map('application', (string) $foreignApplication->getKey(), 'project', (string) $this->project->getKey());
        ProjectResource::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $foreignProject->getKey(), 'product' => 'monitor', 'resource_type' => 'application',
            'resource_id' => (string) $foreignApplication->getKey(), 'name' => 'Archived duplicate claim', 'status' => 'inactive', 'mapped_at' => now(),
        ]);

        $snapshot = app(MonitorServiceObjectiveAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $this->assertSame([], $snapshot->environments);
        $this->assertSame(0, $snapshot->items->total());
    }

    public function test_inactive_foreign_environment_resource_claim_makes_environment_binding_ambiguous(): void
    {
        $this->nativeObjective($this->environment, 'Mapped objective');
        $foreignProject = Project::query()->forceCreate([
            'id' => (string) Str::ulid(), 'workspace_id' => $this->workspace->getKey(), 'created_by_user_id' => $this->actor->getKey(),
            'name' => 'Foreign project', 'slug' => 'foreign-environment-project', 'status' => 'archived', 'archived_at' => now(),
        ]);
        $foreignEnvironment = Environment::query()->forceCreate([
            'application_id' => $this->application->getKey(), 'name' => 'Foreign staging claim', 'slug' => 'foreign-staging-claim', 'status' => 'active',
        ]);
        $this->map('environment', (string) $foreignEnvironment->getKey(), 'project_environment', (string) $this->canonicalEnvironment->getKey());
        ProjectResource::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $foreignProject->getKey(), 'environment_id' => $this->canonicalEnvironment->getKey(),
            'product' => 'monitor', 'resource_type' => 'environment', 'resource_id' => (string) $foreignEnvironment->getKey(),
            'name' => 'Archived duplicate claim', 'status' => 'inactive', 'mapped_at' => now(),
        ]);

        $snapshot = app(MonitorServiceObjectiveAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $this->assertSame([], $snapshot->environments);
        $this->assertSame(0, $snapshot->items->total());
    }

    public function test_identity_only_application_claim_makes_project_binding_ambiguous(): void
    {
        $this->nativeObjective($this->environment, 'Mapped objective');
        $foreignApplication = Application::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'name' => 'Identity-only claim', 'slug' => 'identity-only-claim',
            'framework' => 'Node.js', 'framework_version' => null, 'accent' => 'sky',
        ]);
        $this->map('application', (string) $foreignApplication->getKey(), 'project', (string) $this->project->getKey());

        $snapshot = app(MonitorServiceObjectiveAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $this->assertSame([], $snapshot->environments);
        $this->assertSame(0, $snapshot->items->total());
    }

    public function test_identity_only_environment_claim_makes_environment_binding_ambiguous(): void
    {
        $this->nativeObjective($this->environment, 'Mapped objective');
        $foreignEnvironment = Environment::query()->forceCreate([
            'application_id' => $this->application->getKey(), 'name' => 'Identity-only staging', 'slug' => 'identity-only-staging',
            'status' => 'active',
        ]);
        $this->map('environment', (string) $foreignEnvironment->getKey(), 'project_environment', (string) $this->canonicalEnvironment->getKey());

        $snapshot = app(MonitorServiceObjectiveAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $this->assertSame([], $snapshot->environments);
        $this->assertSame(0, $snapshot->items->total());
    }

    public function test_validation_retry_keeps_the_submitted_optimistic_version(): void
    {
        $provider = app(MonitorServiceObjectiveAdministrationProvider::class);
        $environmentReference = $provider->snapshot($this->actor, $this->workspace)->environments[0]['reference'];
        $provider->saveObjective($this->actor, $this->workspace, null, $this->validObjective($environmentReference));
        $item = $provider->snapshot($this->actor, $this->workspace)->items->items()[0];
        $objective = ServiceLevelObjective::query()->firstOrFail();
        DB::connection('monitor')->table('service_level_objectives')->where('id', $objective->getKey())
            ->update(['updated_at' => '2041-01-01 00:00:00.123456']);

        $this->actingAs($this->actor, 'platform')->from(route('core.workspace.monitor.service-objectives', $this->workspace))
            ->post(route('core.workspace.monitor.service-objectives.update', $this->workspace),
                $this->validObjective($environmentReference, [
                    '_method' => 'PATCH', 'objective_reference' => $item['reference'], 'form_key' => $item['form_key'],
                    'version' => $item['version'], 'target' => 'invalid',
                ]))
            ->assertSessionHasErrors('target');

        $page = $this->get(route('core.workspace.monitor.service-objectives', $this->workspace));
        $page->assertOk()->assertSee('value="'.$item['version'].'"', false);
        $this->assertHttpStatus(409, fn () => $provider->saveObjective(
            $this->actor, $this->workspace, $item['reference'],
            $this->validObjective($environmentReference, ['version' => $item['version'], 'target' => '99.950']),
        ));
        $this->assertSame('Service objective', $objective->fresh()->name);
    }

    public function test_updates_are_environment_immutable_and_use_optimistic_version_checks(): void
    {
        $provider = app(MonitorServiceObjectiveAdministrationProvider::class);
        $secondEnvironment = Environment::query()->forceCreate([
            'application_id' => $this->application->getKey(), 'name' => 'Staging', 'slug' => 'staging', 'status' => 'active',
        ]);
        $secondCanonical = $this->mapEnvironment($secondEnvironment, 'Staging', 'staging');
        ProjectResource::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'environment_id' => $secondCanonical->getKey(),
            'product' => 'monitor', 'resource_type' => 'environment', 'resource_id' => (string) $secondEnvironment->getKey(),
            'name' => 'Staging', 'status' => 'active', 'mapped_at' => now(),
        ]);
        $initial = $provider->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($initial);
        $environmentReferences = collect($initial->environments)->keyBy('label');
        $created = $provider->saveObjective($this->actor, $this->workspace, null, $this->validObjective(
            $environmentReferences->get('Public API / Production')['reference'],
        ));
        $objective = ServiceLevelObjective::query()->where('environment_id', $this->environment->getKey())->firstOrFail();
        $snapshot = $provider->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $item = $snapshot->items->items()[0];

        try {
            $provider->saveObjective($this->actor, $this->workspace, $created->reference, $this->validObjective(
                $environmentReferences->get('Public API / Staging')['reference'], ['version' => $item['version']],
            ));
            $this->fail('An existing SLO must stay bound to its original environment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('environment_reference', $exception->errors());
        }

        DB::connection('monitor')->table('service_level_objectives')->where('id', $objective->getKey())
            ->update(['updated_at' => '2041-01-01 00:00:00.123456']);
        $this->assertHttpStatus(409, fn () => $provider->saveObjective(
            $this->actor, $this->workspace, $created->reference,
            $this->validObjective($item['environment_reference'], ['version' => $item['version'], 'name' => 'Stale update']),
        ));
        $this->assertSame('Service objective', $objective->fresh()->name);
    }

    public function test_core_viewer_and_native_viewer_cannot_mutate_service_objectives(): void
    {
        $provider = app(MonitorServiceObjectiveAdministrationProvider::class);
        $snapshot = $provider->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $environmentReference = $snapshot->environments[0]['reference'];
        $this->membership->update(['role' => 'viewer']);
        $this->actingAs($this->actor, 'platform')->post(
            route('core.workspace.monitor.service-objectives.store', $this->workspace),
            $this->validObjective($environmentReference),
        )->assertForbidden();
        $this->assertDatabaseCount('service_level_objectives', 0, 'monitor');

        $this->membership->update(['role' => 'owner']);
        $this->monitorWorkspace->members()->updateExistingPivot($this->monitorActor->getKey(), ['role' => 'viewer']);
        $snapshot = $provider->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $this->assertFalse($snapshot->canManage);
        $this->expectException(AuthorizationException::class);
        $provider->saveObjective($this->actor, $this->workspace, null, $this->validObjective($environmentReference));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function validObjective(string $environmentReference, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Service objective',
            'environment_reference' => $environmentReference,
            'indicator' => 'availability',
            'service' => null,
            'route' => null,
            'target' => '99.900',
            'window_days' => '30',
            'status_min' => '200',
            'status_max' => '399',
            'enabled' => '1',
        ], $overrides);
    }

    private function nativeObjective(Environment $environment, string $name): ServiceLevelObjective
    {
        return ServiceLevelObjective::query()->forceCreate([
            'environment_id' => $environment->getKey(), 'name' => $name, 'indicator' => 'availability',
            'target' => 99.9, 'window_days' => 30, 'latency_threshold_ms' => null,
            'status_min' => 200, 'status_max' => 399, 'enabled' => true,
        ]);
    }

    private function mapEnvironment(Environment $environment, string $name, string $slug): ProjectEnvironment
    {
        $canonical = ProjectEnvironment::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'created_by_user_id' => $this->actor->getKey(),
            'name' => $name, 'slug' => $slug, 'environment_type' => $name === 'Production' ? 'production' : 'staging', 'status' => 'active',
        ]);
        $this->map('environment', (string) $environment->getKey(), 'project_environment', (string) $canonical->getKey());

        return $canonical;
    }

    private function map(string $sourceEntity, string $sourceId, string $canonicalEntity, string $canonicalId): void
    {
        LegacyIdentityMap::query()->forceCreate([
            'id' => (string) Str::ulid(), 'source_product' => 'monitor', 'source_entity' => $sourceEntity,
            'source_id' => $sourceId, 'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId,
            'status' => 'reconciled',
        ]);
    }

    private function assertHttpStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail('The operation should have been rejected with HTTP '.$status.'.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        }
    }
}
