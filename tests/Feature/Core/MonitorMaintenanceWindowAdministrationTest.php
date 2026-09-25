<?php

namespace Tests\Feature\Core;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\WorkspaceMonitorAdministrationRegistry;
use App\Modules\Monitor\Models\MaintenanceWindow;
use App\Modules\Monitor\Models\User as MonitorUser;
use App\Modules\Monitor\Models\Workspace as MonitorWorkspace;
use App\Modules\Monitor\Policies\WorkspacePolicy;
use App\Modules\Monitor\Services\Core\MonitorAdministrationContext;
use App\Modules\Monitor\Services\Core\MonitorMaintenanceWindowAdministrationProvider;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Regression coverage authored for Core Monitor maintenance windows; intentionally not executed here. */
final class MonitorMaintenanceWindowAdministrationTest extends TestCase
{
    private PlatformUser $actor;

    private CoreWorkspace $workspace;

    private WorkspaceMembership $membership;

    private WorkspaceProductAccess $monitorGrant;

    private MonitorUser $monitorActor;

    private MonitorWorkspace $monitorWorkspace;

    private LegacyIdentityMap $workspaceMap;

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

        $this->actor = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(), 'name' => 'Core owner', 'email' => 'monitor-window-owner@example.test',
            'email_normalized' => 'monitor-window-owner@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $this->workspace = CoreWorkspace::query()->forceCreate([
            'id' => (string) Str::ulid(), 'owner_user_id' => $this->actor->getKey(),
            'name' => 'Core workspace', 'slug' => 'monitor-window-core', 'status' => 'active',
        ]);
        $this->membership = WorkspaceMembership::query()->forceCreate([
            'id' => (string) Str::ulid(), 'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->actor->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->monitorGrant = WorkspaceProductAccess::query()->forceCreate([
            'id' => (string) Str::ulid(), 'membership_id' => $this->membership->getKey(), 'product' => 'monitor',
            'role' => 'owner', 'status' => 'active', 'granted_at' => now(),
        ]);

        $this->monitorActor = MonitorUser::query()->forceCreate([
            'name' => 'Monitor owner', 'email' => 'monitor-window-native@example.test',
            'password' => 'hashed', 'email_verified_at' => now(),
        ]);
        $this->monitorWorkspace = MonitorWorkspace::query()->forceCreate([
            'owner_id' => $this->monitorActor->getKey(), 'name' => 'Monitor workspace',
            'slug' => 'monitor-window-source', 'plan' => 'free',
        ]);
        $this->monitorWorkspace->members()->attach($this->monitorActor, ['role' => 'owner']);

        $this->map('user', (string) $this->monitorActor->getKey(), 'user', (string) $this->actor->getKey());
        $this->workspaceMap = $this->map(
            'workspace', (string) $this->monitorWorkspace->getKey(), 'workspace', (string) $this->workspace->getKey(),
        );
        app(WorkspaceMonitorAdministrationRegistry::class)->registerMaintenanceWindows(
            app(MonitorMaintenanceWindowAdministrationProvider::class),
        );
    }

    public function test_core_manager_can_create_update_and_delete_through_the_native_action(): void
    {
        $provider = app(MonitorMaintenanceWindowAdministrationProvider::class);
        $created = $provider->saveWindow($this->actor, $this->workspace, null, $this->validWindow([
            'name' => 'Release maintenance',
            'reason' => 'Deploying a new release.',
        ]));

        $window = MaintenanceWindow::query()->whereBelongsTo($this->monitorWorkspace)->firstOrFail();
        $this->assertSame((string) $this->monitorActor->getKey(), (string) $window->created_by);
        $this->assertSame('Release maintenance', $window->name);
        $this->assertSame('Deploying a new release.', $window->reason);

        $snapshot = $provider->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $this->assertCount(1, $snapshot->items->items());
        $item = $snapshot->items->items()[0];
        $this->assertSame('2040-01-01T01:00', $item['starts_at']);
        $this->assertSame('2040-01-01T02:00', $item['ends_at']);

        $provider->saveWindow($this->actor, $this->workspace, $created->reference, $this->validWindow([
            'version' => $item['version'],
            'name' => 'Release maintenance updated',
            'reason' => 'Deploying a verified release.',
        ]));
        $this->assertSame('Release maintenance updated', $window->fresh()->name);
        $this->assertSame('Deploying a verified release.', $window->fresh()->reason);

        $updated = $provider->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($updated);
        $provider->deleteWindow($this->actor, $this->workspace, $item['reference'], $updated->items->items()[0]['version'], true);
        $this->assertDatabaseCount('maintenance_windows', 0, 'monitor');
    }

    public function test_snapshot_is_workspace_scoped_and_uses_twenty_row_pages(): void
    {
        for ($index = 1; $index <= 21; $index++) {
            $startsAt = CarbonImmutable::now('UTC')->addDays($index);
            MaintenanceWindow::query()->forceCreate([
                'workspace_id' => $this->monitorWorkspace->getKey(),
                'created_by' => $this->monitorActor->getKey(),
                'name' => sprintf('Window %02d', $index),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addHour(),
            ]);
        }
        $foreignWorkspace = MonitorWorkspace::query()->forceCreate([
            'owner_id' => $this->monitorActor->getKey(), 'name' => 'Other Monitor workspace',
            'slug' => 'monitor-window-other', 'plan' => 'free',
        ]);
        $foreignWorkspace->members()->attach($this->monitorActor, ['role' => 'owner']);
        MaintenanceWindow::query()->forceCreate([
            'workspace_id' => $foreignWorkspace->getKey(), 'created_by' => $this->monitorActor->getKey(),
            'name' => 'Foreign window', 'starts_at' => now('UTC')->addDays(30), 'ends_at' => now('UTC')->addDays(30)->addHour(),
        ]);

        $provider = app(MonitorMaintenanceWindowAdministrationProvider::class);
        $firstPage = $provider->snapshot($this->actor, $this->workspace);
        $secondPage = $provider->snapshot($this->actor, $this->workspace, ['maintenance_page' => 2]);

        $this->assertNotNull($firstPage);
        $this->assertNotNull($secondPage);
        $this->assertSame(21, $firstPage->items->total());
        $this->assertCount(20, $firstPage->items->items());
        $this->assertSame(2, $secondPage->items->currentPage());
        $this->assertCount(1, $secondPage->items->items());
        $this->assertNotContains('Foreign window', array_column($firstPage->items->items(), 'name'));
        $this->assertArrayNotHasKey('id', $firstPage->items->items()[0]);
    }

    public function test_core_viewer_can_read_but_cannot_mutate_and_revoked_grant_hides_the_page(): void
    {
        MaintenanceWindow::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'created_by' => $this->monitorActor->getKey(),
            'name' => 'Visible to workspace viewers', 'starts_at' => now('UTC')->addHour(), 'ends_at' => now('UTC')->addHours(2),
        ]);
        $this->membership->update(['role' => 'viewer']);
        $url = route('core.workspace.monitor.maintenance-windows', $this->workspace);

        $this->actingAs($this->actor, 'platform')->get($url)->assertOk()->assertSee('Visible to workspace viewers');
        $this->actingAs($this->actor, 'platform')
            ->post(route('core.workspace.monitor.maintenance-windows.store', $this->workspace), $this->validWindow())
            ->assertForbidden();
        $this->assertDatabaseCount('maintenance_windows', 1, 'monitor');

        $this->monitorGrant->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->actingAs($this->actor, 'platform')->get($url)->assertNotFound();
    }

    public function test_native_viewer_cannot_mutate_even_when_the_core_role_is_a_manager(): void
    {
        $this->monitorWorkspace->members()->updateExistingPivot($this->monitorActor->getKey(), ['role' => 'viewer']);
        $provider = app(MonitorMaintenanceWindowAdministrationProvider::class);
        $snapshot = $provider->snapshot($this->actor, $this->workspace);

        $this->assertNotNull($snapshot);
        $this->assertFalse($snapshot->canManage);
        $this->expectException(AuthorizationException::class);
        $provider->saveWindow($this->actor, $this->workspace, null, $this->validWindow());
    }

    public function test_ambiguous_native_user_mapping_hides_the_page_even_if_only_one_user_is_a_member(): void
    {
        $otherNativeUser = MonitorUser::query()->forceCreate([
            'name' => 'Unrelated Monitor user',
            'email' => 'monitor-window-extra@example.test',
            'password' => 'hashed',
            'email_verified_at' => now(),
        ]);
        $this->map('user', (string) $otherNativeUser->getKey(), 'user', (string) $this->actor->getKey());

        $provider = app(MonitorMaintenanceWindowAdministrationProvider::class);
        $this->assertNull($provider->snapshot($this->actor, $this->workspace));
        $this->actingAs($this->actor, 'platform')
            ->get(route('core.workspace.monitor.maintenance-windows', $this->workspace))
            ->assertNotFound();
    }

    public function test_update_route_requires_an_existing_window_reference(): void
    {
        $this->actingAs($this->actor, 'platform')
            ->patch(route('core.workspace.monitor.maintenance-windows.update', $this->workspace), $this->validWindow())
            ->assertSessionHasErrors('window_reference');

        $this->assertDatabaseCount('maintenance_windows', 0, 'monitor');
    }

    public function test_delete_requires_explicit_confirmation(): void
    {
        $window = MaintenanceWindow::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(),
            'created_by' => $this->monitorActor->getKey(),
            'name' => 'Scheduled suppression',
            'starts_at' => now('UTC')->addHour(),
            'ends_at' => now('UTC')->addHours(2),
        ]);
        $snapshot = app(MonitorMaintenanceWindowAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $item = $snapshot->items->items()[0];

        $this->actingAs($this->actor, 'platform')->delete(
            route('core.workspace.monitor.maintenance-windows.destroy', $this->workspace),
            ['window_reference' => $item['reference'], 'version' => $item['version']],
        )->assertSessionHasErrors('confirm_remove');

        $this->assertDatabaseHas('maintenance_windows', ['id' => $window->getKey()], 'monitor');
    }

    public function test_foreign_workspace_references_and_foreign_ids_are_denied(): void
    {
        $foreignWorkspace = MonitorWorkspace::query()->forceCreate([
            'owner_id' => $this->monitorActor->getKey(), 'name' => 'Other Monitor workspace',
            'slug' => 'monitor-window-reference-other', 'plan' => 'free',
        ]);
        $foreignWorkspace->members()->attach($this->monitorActor, ['role' => 'owner']);
        $foreignWindow = MaintenanceWindow::query()->forceCreate([
            'workspace_id' => $foreignWorkspace->getKey(), 'created_by' => $this->monitorActor->getKey(),
            'name' => 'Foreign window', 'starts_at' => now('UTC')->addHour(), 'ends_at' => now('UTC')->addHours(2),
        ]);
        $context = app(MonitorAdministrationContext::class);
        $provider = app(MonitorMaintenanceWindowAdministrationProvider::class);
        $workspaceBoundReference = $context->reference('maintenance-window', $foreignWindow->getKey(), $this->monitorWorkspace);
        $foreignBoundReference = $context->reference('maintenance-window', $foreignWindow->getKey(), $foreignWorkspace);
        $version = (string) $foreignWindow->getRawOriginal('updated_at');

        $this->assertHttpStatus(404, fn () => $provider->deleteWindow($this->actor, $this->workspace, $foreignBoundReference, $version, true));
        try {
            $provider->deleteWindow($this->actor, $this->workspace, $workspaceBoundReference, $version, true);
            $this->fail('A native ID outside the resolved workspace must be hidden.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('maintenance_windows', 1, 'monitor');
        }
    }

    public function test_stale_versions_and_invalid_intervals_do_not_change_native_rows(): void
    {
        $window = MaintenanceWindow::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'created_by' => $this->monitorActor->getKey(),
            'name' => 'Original name', 'starts_at' => '2040-01-01 01:00:00', 'ends_at' => '2040-01-01 02:00:00',
        ]);
        $snapshot = app(MonitorMaintenanceWindowAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $item = $snapshot->items->items()[0];

        // Simulate a competing SQLite writer committing after the rendered snapshot.
        DB::connection('monitor')->table('maintenance_windows')->where('id', $window->getKey())
            ->update(['updated_at' => '2041-01-01 00:00:00.123456']);
        $this->assertHttpStatus(409, fn () => app(MonitorMaintenanceWindowAdministrationProvider::class)->saveWindow(
            $this->actor, $this->workspace, $item['reference'], $this->validWindow([
                'version' => $item['version'], 'name' => 'Stale edit',
            ]),
        ));
        $this->assertSame('Original name', $window->fresh()->name);

        try {
            app(MonitorMaintenanceWindowAdministrationProvider::class)->saveWindow(
                $this->actor, $this->workspace, null, $this->validWindow([
                    'name' => 'Invalid interval', 'starts_at' => '2040-01-01T02:00', 'ends_at' => '2040-01-01T01:00',
                ]),
            );
            $this->fail('An interval ending before its start must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ends_at', $exception->errors());
        }
        $this->assertDatabaseCount('maintenance_windows', 1, 'monitor');
    }

    public function test_rows_without_a_valid_updated_at_remain_visible_but_read_only(): void
    {
        $window = MaintenanceWindow::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(),
            'created_by' => $this->monitorActor->getKey(),
            'name' => 'Legacy window without a version',
            'starts_at' => '2040-01-01 01:00:00',
            'ends_at' => '2040-01-01 02:00:00',
        ]);
        DB::connection('monitor')->table('maintenance_windows')->where('id', $window->getKey())->update(['updated_at' => null]);

        $provider = app(MonitorMaintenanceWindowAdministrationProvider::class);
        $snapshot = $provider->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $item = $snapshot->items->items()[0];
        $this->assertSame('Legacy window without a version', $item['name']);
        $this->assertNull($item['version']);
        $this->assertFalse($item['can_mutate']);

        $this->actingAs($this->actor, 'platform')
            ->get(route('core.workspace.monitor.maintenance-windows', $this->workspace))
            ->assertOk()
            ->assertSee('Legacy window without a version')
            ->assertSee('saved window version is unavailable')
            ->assertDontSee('Save window')
            ->assertDontSee('Remove window');

        $this->assertHttpStatus(409, fn () => $provider->saveWindow(
            $this->actor, $this->workspace, $item['reference'], $this->validWindow(['version' => '2040-01-01 00:00:00']),
        ));
    }

    public function test_failed_edit_restores_values_only_to_the_submitted_window_form(): void
    {
        $first = $this->legacyWindow('First window');
        $this->legacyWindow('Second window');
        $snapshot = app(MonitorMaintenanceWindowAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $firstItem = collect($snapshot->items->items())->firstWhere('name', $first->name);
        $this->assertNotNull($firstItem);

        $response = $this->actingAs($this->actor, 'platform')
            ->withSession(['_old_input' => [
                '_method' => 'PATCH',
                'form_key' => $firstItem['form_key'],
                'name' => 'Submitted edit',
                'reason' => 'A corrected reason',
            ]])
            ->get(route('core.workspace.monitor.maintenance-windows', $this->workspace));

        $response->assertOk()->assertSee('value="Submitted edit"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'value="Submitted edit"'));
        $response->assertSee('value="Second window"', false);
    }

    public function test_legacy_second_precision_versions_can_still_be_edited_and_deleted(): void
    {
        $editable = $this->legacyWindow('Legacy edit');
        $deletable = $this->legacyWindow('Legacy delete');
        $snapshot = app(MonitorMaintenanceWindowAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($snapshot);
        $items = collect($snapshot->items->items())->keyBy('name');
        $editItem = $items->get('Legacy edit');
        $deleteItem = $items->get('Legacy delete');
        $this->assertNotNull($editItem);
        $this->assertNotNull($deleteItem);
        $this->assertSame('2042-01-02 03:04:05', $editItem['version']);
        $this->assertSame('2042-01-02 03:04:05', $deleteItem['version']);

        $this->actingAs($this->actor, 'platform')->patch(
            route('core.workspace.monitor.maintenance-windows.update', $this->workspace),
            $this->validWindow([
                'window_reference' => $editItem['reference'],
                'version' => $editItem['version'],
                'name' => 'Legacy edit saved',
            ]),
        )->assertRedirect(route('core.workspace.monitor.maintenance-windows', $this->workspace));
        $this->assertSame('Legacy edit saved', $editable->fresh()->name);

        $this->actingAs($this->actor, 'platform')->delete(
            route('core.workspace.monitor.maintenance-windows.destroy', $this->workspace),
            ['window_reference' => $deleteItem['reference'], 'version' => $deleteItem['version'], 'confirm_remove' => '1'],
        )->assertRedirect(route('core.workspace.monitor.maintenance-windows', $this->workspace));
        $this->assertDatabaseMissing('maintenance_windows', ['id' => $deletable->getKey()], 'monitor');
    }

    public function test_stale_identity_and_deletion_fence_reject_mutations(): void
    {
        $provider = app(MonitorMaintenanceWindowAdministrationProvider::class);
        $this->workspaceMap->update(['status' => 'retired']);
        $this->assertHttpStatus(404, fn () => $provider->saveWindow($this->actor, $this->workspace, null, $this->validWindow()));
        $this->workspaceMap->update(['status' => 'reconciled']);

        $context = app(MonitorAdministrationContext::class);
        $resolved = $context->resolve($this->actor, $this->workspace);
        $this->assertNotNull($resolved);
        DB::connection('monitor')->table('product_deletion_fences')->insert([
            'kind' => 'workspace',
            'source_id' => (string) $this->monitorWorkspace->getKey(),
            'request_id' => (string) Str::ulid(),
            'step_id' => (string) Str::ulid(),
            'payload_hash' => str_repeat('a', 64),
            'target' => json_encode(['canonicalId' => (string) $this->workspace->getKey()], JSON_THROW_ON_ERROR),
            'status' => 'prepared',
            'prepared_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertHttpStatus(410, fn () => $context->mutate(
            $this->actor, $this->workspace, $resolved['workspace'], $resolved['user'], fn () => null,
        ));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function validWindow(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Planned maintenance',
            'reason' => 'Routine maintenance.',
            'starts_at' => '2040-01-01T01:00',
            'ends_at' => '2040-01-01T02:00',
        ], $overrides);
    }

    private function legacyWindow(string $name): MaintenanceWindow
    {
        $window = MaintenanceWindow::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(),
            'created_by' => $this->monitorActor->getKey(),
            'name' => $name,
            'reason' => null,
            'starts_at' => '2042-01-02 03:04:05',
            'ends_at' => '2042-01-02 04:04:05',
        ]);
        DB::connection('monitor')->table('maintenance_windows')->where('id', $window->getKey())
            ->update(['updated_at' => '2042-01-02 03:04:05']);

        return $window->fresh();
    }

    private function assertHttpStatus(int $expected, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected an HTTP {$expected} response.");
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($expected, $exception->getStatusCode());
        }
    }

    private function map(string $sourceEntity, string $sourceId, string $canonicalEntity, string $canonicalId): LegacyIdentityMap
    {
        return LegacyIdentityMap::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'source_product' => 'monitor',
            'source_entity' => $sourceEntity,
            'source_id' => $sourceId,
            'canonical_entity' => $canonicalEntity,
            'canonical_id' => $canonicalId,
            'status' => 'reconciled',
        ]);
    }
}
