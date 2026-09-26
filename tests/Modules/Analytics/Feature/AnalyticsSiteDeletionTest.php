<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\WorkspaceAnalyticsAdministrationProviderRegistry;
use App\Modules\Analytics\Console\Commands\ProcessSiteDeletions;
use App\Modules\Analytics\Console\Commands\PruneAnalyticsData;
use App\Modules\Analytics\Enums\IngestionStatus;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\SiteDeletionOperation;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use App\Modules\Analytics\Services\Core\AnalyticsWorkspaceSiteAdministrationProvider;
use App\Modules\Analytics\Services\Deletion\AnalyticsSiteDeletionService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

final class AnalyticsSiteDeletionTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_site_deletion_fences_pending_work_and_removes_only_its_exports_and_relational_data(): void
    {
        [$actor, $nativeWorkspace, $site] = $this->mappedOwnerFixture();
        $otherSite = $nativeWorkspace->sites()->create(['name' => 'Keep me', 'domains' => ['keep.example.test'], 'timezone' => 'UTC']);
        $batch = $site->ingestionBatches()->create([
            'batch_id' => (string) Str::uuid(), 'event_count' => 1, 'status' => IngestionStatus::Pending->value, 'accepted_at' => now(),
        ]);
        $site->events()->create([
            'ingestion_batch_id' => $batch->getKey(), 'event_id' => (string) Str::uuid(), 'type' => 'page_view',
            'occurred_at' => now(), 'received_at' => now(), 'path' => '/', 'properties' => [],
        ]);
        $nativeRequesterId = $nativeWorkspace->users()->value('users.id');
        $targetExport = ReportExport::query()->create([
            'workspace_id' => $nativeWorkspace->getKey(), 'site_id' => $site->getKey(), 'requested_by' => $nativeRequesterId,
            'token_hash' => hash('sha256', 'target-token'), 'filters' => ['days' => 30], 'status' => 'processing',
            'expires_at' => now()->addDay(),
        ]);
        $targetExport->update(['file_path' => 'exports/'.$targetExport->getKey().'.csv']);
        $otherExport = ReportExport::query()->create([
            'workspace_id' => $nativeWorkspace->getKey(), 'site_id' => $otherSite->getKey(), 'requested_by' => $nativeRequesterId,
            'token_hash' => hash('sha256', 'other-token'), 'filters' => ['days' => 30], 'status' => 'completed',
            'expires_at' => now()->addDay(),
        ]);
        $otherExport->update(['file_path' => 'exports/'.$otherExport->getKey().'.csv']);
        Storage::fake('analytics-local');
        Storage::disk('analytics-local')->put($targetExport->file_path, 'private target report');
        Storage::disk('analytics-local')->put($otherExport->file_path, 'private other report');

        $outcome = app(AnalyticsSiteDeletionService::class)->request($actor, $site, $site->slug,
            (string) $actor->workspaceMemberships()->value('workspace_id'), (string) $actor->getKey());

        $this->assertTrue($outcome->completed());
        $this->assertTrue($site->fresh()->trashed());
        $this->assertDatabaseMissing('ingestion_batches', ['id' => $batch->getKey()], 'analytics');
        $this->assertDatabaseMissing('analytics_events', ['site_id' => $site->getKey()], 'analytics');
        $this->assertDatabaseMissing('report_exports', ['id' => $targetExport->getKey()], 'analytics');
        $this->assertDatabaseHas('report_exports', ['id' => $otherExport->getKey()], 'analytics');
        $this->assertFalse(Storage::disk('analytics-local')->exists($targetExport->file_path));
        $this->assertTrue(Storage::disk('analytics-local')->exists($otherExport->file_path));
        $this->assertSame('completed', SiteDeletionOperation::query()->sole()->status);
    }

    public function test_failed_file_removal_keeps_manifest_and_rows_for_durable_retry(): void
    {
        [$actor, $nativeWorkspace, $site] = $this->mappedOwnerFixture();
        $requesterId = $nativeWorkspace->users()->value('users.id');
        $export = ReportExport::query()->create([
            'workspace_id' => $nativeWorkspace->getKey(), 'site_id' => $site->getKey(), 'requested_by' => $requesterId,
            'token_hash' => hash('sha256', 'retry-token'), 'filters' => ['days' => 30], 'status' => 'completed',
            'file_path' => null, 'expires_at' => now()->addDay(),
        ]);
        Storage::fake('analytics-local');
        $path = 'exports/'.$export->getKey().'.csv';
        Storage::disk('analytics-local')->put($path, 'private report');
        $realDisk = Storage::disk('analytics-local');
        $deleteCalls = 0;
        $disk = Mockery::mock($realDisk)->makePartial();
        $disk->shouldReceive('delete')->with($path)->twice()->andReturnUsing(function (string $candidate) use (&$deleteCalls, $realDisk): bool {
            $deleteCalls++;

            return $deleteCalls === 1 ? false : $realDisk->delete($candidate);
        });
        Storage::shouldReceive('disk')->with('analytics-local')->andReturn($disk);

        $service = app(AnalyticsSiteDeletionService::class);
        $first = $service->request($actor, $site, $site->slug,
            (string) $actor->workspaceMemberships()->value('workspace_id'), (string) $actor->getKey());
        $operation = SiteDeletionOperation::query()->sole();
        $this->assertSame('waiting', $first->status);
        $this->assertContains($path, $operation->file_manifest);
        $this->assertDatabaseHas('report_exports', ['id' => $export->getKey()], 'analytics');
        $this->assertFalse($site->fresh()->trashed());

        $retry = $service->retry((string) $operation->getKey(), $actor);

        $this->assertTrue($retry->completed());
        $this->assertFalse($realDisk->exists($path));
        $this->assertDatabaseMissing('report_exports', ['id' => $export->getKey()], 'analytics');
    }

    public function test_pruning_preserves_export_ownership_when_pending_deletion_cannot_build_its_manifest(): void
    {
        [$actor, $nativeWorkspace, $site] = $this->mappedOwnerFixture();
        $export = ReportExport::query()->create([
            'workspace_id' => $nativeWorkspace->getKey(), 'site_id' => $site->getKey(),
            'requested_by' => $nativeWorkspace->users()->value('users.id'), 'token_hash' => hash('sha256', 'pending-delete-token'),
            'filters' => ['days' => 30], 'status' => 'completed',
            'expires_at' => now()->subMinute(),
        ]);
        $export->update(['file_path' => 'exports/'.$export->getKey().'.csv']);
        Storage::fake('analytics-local');
        $path = (string) $export->file_path;
        Storage::disk('analytics-local')->put($path, 'private report');
        $realDisk = Storage::disk('analytics-local');
        $disk = Mockery::mock($realDisk)->makePartial();
        $disk->shouldReceive('allFiles')->with('exports')->once()->andThrow(new RuntimeException('Storage listing failed.'));
        $disk->shouldReceive('delete')->never();
        Storage::shouldReceive('disk')->with('analytics-local')->andReturn($disk);

        $outcome = app(AnalyticsSiteDeletionService::class)->request($actor, $site, $site->slug,
            (string) $actor->workspaceMemberships()->value('workspace_id'), (string) $actor->getKey());

        $this->assertSame('waiting', $outcome->status);
        $operation = SiteDeletionOperation::query()->sole();
        $this->assertNull($operation->manifest_at);
        $this->assertSame('export_manifest_unavailable', $operation->last_error_code);
        $this->registerAnalyticsConsoleCommand(PruneAnalyticsData::class);
        $this->assertSame(0, Artisan::call('analytics:prune'));

        $this->assertDatabaseHas('report_exports', ['id' => $export->getKey()], 'analytics');
        $this->assertTrue($realDisk->exists($path));
        $this->assertFalse($site->fresh()->trashed());
    }

    public function test_pruning_keeps_an_export_with_no_persisted_path_when_storage_listing_fails(): void
    {
        [, $nativeWorkspace, $site] = $this->mappedOwnerFixture();
        $export = ReportExport::query()->create([
            'workspace_id' => $nativeWorkspace->getKey(), 'site_id' => $site->getKey(),
            'requested_by' => $nativeWorkspace->users()->value('users.id'), 'token_hash' => hash('sha256', 'orphaned-file-token'),
            'filters' => ['days' => 30], 'status' => 'failed', 'file_path' => null,
            'expires_at' => now()->subMinute(),
        ]);
        Storage::fake('analytics-local');
        $path = 'exports/'.$export->getKey().'.csv';
        Storage::disk('analytics-local')->put($path, 'private report written before its database path');
        $realDisk = Storage::disk('analytics-local');
        $disk = Mockery::mock($realDisk)->makePartial();
        $disk->shouldReceive('allFiles')->with('exports')->once()->andThrow(new RuntimeException('Storage listing failed.'));
        $disk->shouldReceive('delete')->never();
        Storage::shouldReceive('disk')->with('analytics-local')->andReturn($disk);

        $this->registerAnalyticsConsoleCommand(PruneAnalyticsData::class);
        $this->assertSame(0, Artisan::call('analytics:prune'));

        $this->assertDatabaseHas('report_exports', ['id' => $export->getKey()], 'analytics');
        $this->assertTrue($realDisk->exists($path));
    }

    public function test_pruning_keeps_export_ownership_when_file_removal_fails(): void
    {
        [, $nativeWorkspace, $site] = $this->mappedOwnerFixture();
        $export = ReportExport::query()->create([
            'workspace_id' => $nativeWorkspace->getKey(), 'site_id' => $site->getKey(),
            'requested_by' => $nativeWorkspace->users()->value('users.id'), 'token_hash' => hash('sha256', 'failed-prune-token'),
            'filters' => ['days' => 30], 'status' => 'completed', 'file_path' => null,
            'expires_at' => now()->subMinute(),
        ]);
        Storage::fake('analytics-local');
        $path = 'exports/'.$export->getKey().'.csv';
        Storage::disk('analytics-local')->put($path, 'private report');
        $realDisk = Storage::disk('analytics-local');
        $disk = Mockery::mock($realDisk)->makePartial();
        $disk->shouldReceive('allFiles')->with('exports')->once()->andReturn([$path]);
        $disk->shouldReceive('delete')->with($path)->once()->andReturn(false);
        $disk->shouldReceive('exists')->with($path)->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('analytics-local')->andReturn($disk);

        $this->registerAnalyticsConsoleCommand(PruneAnalyticsData::class);
        $this->assertSame(0, Artisan::call('analytics:prune'));

        $this->assertDatabaseHas('report_exports', ['id' => $export->getKey()], 'analytics');
        $this->assertTrue($realDisk->exists($path));
    }

    public function test_core_site_deletion_accepts_a_confirmation_at_the_persisted_slug_length_boundary(): void
    {
        [$actor, $nativeWorkspace, $site] = $this->mappedOwnerFixture();
        $coreWorkspace = CoreWorkspace::query()->findOrFail($actor->workspaceMemberships()->value('workspace_id'));
        $site->update(['slug' => str_repeat('a', 255)]);
        Storage::fake('analytics-local');
        app(WorkspaceAnalyticsAdministrationProviderRegistry::class)->registerSites(
            app(AnalyticsWorkspaceSiteAdministrationProvider::class),
        );
        $csrf = 'analytics-site-delete-csrf';

        $this->withSession(['_token' => $csrf, 'auth.password_confirmed_at' => now()->timestamp])
            ->actingAs($actor, 'platform')
            ->delete(route('core.workspace.analytics.sites.delete', [$coreWorkspace, $site]), [
                '_token' => $csrf, 'confirmation' => $site->slug,
            ])
            ->assertRedirect(route('core.workspace.analytics.sites.index', $coreWorkspace));

        $this->assertTrue($site->fresh()->trashed());
        $this->assertDatabaseHas('site_deletion_operations', [
            'site_source_id' => (string) $site->getKey(), 'workspace_source_id' => (string) $nativeWorkspace->getKey(),
            'status' => 'completed',
        ], 'analytics');
    }

    public function test_core_site_deletion_retry_rejects_an_operation_from_another_native_workspace(): void
    {
        [$actor, $nativeWorkspace, $site] = $this->mappedOwnerFixture();
        $nativeRequester = $nativeWorkspace->users()->firstOrFail();
        $operation = SiteDeletionOperation::query()->create([
            'id' => (string) Str::ulid(), 'site_source_id' => (string) $site->getKey(),
            'workspace_source_id' => (string) $nativeWorkspace->getKey(),
            'requester_source_id' => (string) $nativeRequester->getKey(),
            'canonical_workspace_id' => (string) $actor->workspaceMemberships()->value('workspace_id'),
            'canonical_requester_id' => (string) $actor->getKey(), 'payload_hash' => hash('sha256', 'pending-delete'),
            'status' => 'waiting', 'last_error_code' => 'export_manifest_unavailable',
        ]);
        [$otherCoreWorkspace] = $this->addMappedOwnerWorkspace($actor, $nativeRequester);

        try {
            app(AnalyticsWorkspaceSiteAdministrationProvider::class)->retryDeletion(
                $actor, $otherCoreWorkspace, (string) $operation->getKey(),
            );
            $this->fail('A deletion retry must be bound to the workspace in its accepted operation.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame('waiting', $operation->fresh()->status);
        $this->assertFalse($site->fresh()->trashed());
    }

    public function test_site_deletion_worker_rotates_past_older_operations_after_attempting_them(): void
    {
        $now = now();
        $operations = collect(range(0, 2))->map(function (int $index) use ($now): SiteDeletionOperation {
            $updatedAt = $now->copy()->subMinutes(10)->addMinutes($index);

            return SiteDeletionOperation::query()->create([
                'id' => (string) Str::ulid(), 'site_source_id' => 'missing-site-'.$index,
                'workspace_source_id' => 'missing-workspace', 'requester_source_id' => 'missing-requester',
                'canonical_workspace_id' => null, 'canonical_requester_id' => null,
                'payload_hash' => hash('sha256', 'missing-'.$index), 'status' => 'fencing',
                'created_at' => $updatedAt, 'updated_at' => $updatedAt,
            ]);
        });

        $this->registerAnalyticsConsoleCommand(ProcessSiteDeletions::class);
        $this->assertSame(0, Artisan::call('analytics:process-site-deletions', ['--limit' => 2]));
        $this->assertSame('blocked', $operations[0]->fresh()->status);
        $this->assertSame('blocked', $operations[1]->fresh()->status);
        $this->assertSame('fencing', $operations[2]->fresh()->status);

        $this->assertSame(0, Artisan::call('analytics:process-site-deletions', ['--limit' => 1]));
        $this->assertSame('blocked', $operations[2]->fresh()->status);
    }

    public function test_deletion_requires_current_native_owner_policy_and_matching_confirmation(): void
    {
        [$actor, , $site] = $this->mappedOwnerFixture();
        $this->expectException(HttpExceptionInterface::class);
        app(AnalyticsSiteDeletionService::class)->request($actor, $site, 'different-site-slug');
    }

    public function test_current_native_non_owner_cannot_accept_site_deletion(): void
    {
        [$actor, $nativeWorkspace, $site] = $this->mappedOwnerFixture();
        $nativeUserId = $nativeWorkspace->users()->value('users.id');
        $nativeWorkspace->users()->updateExistingPivot($nativeUserId, ['role' => WorkspaceRole::Admin->value]);

        $this->expectException(HttpExceptionInterface::class);
        app(AnalyticsSiteDeletionService::class)->request($actor, $site, $site->slug,
            (string) $actor->workspaceMemberships()->value('workspace_id'), (string) $actor->getKey());
    }

    public function test_unowned_export_path_blocks_cleanup_without_deleting_another_file(): void
    {
        [$actor, $nativeWorkspace, $site] = $this->mappedOwnerFixture();
        $nativeRequesterId = $nativeWorkspace->users()->value('users.id');
        $otherSite = $nativeWorkspace->sites()->create(['name' => 'Other', 'domains' => ['other.example.test'], 'timezone' => 'UTC']);
        $otherExport = ReportExport::query()->create([
            'workspace_id' => $nativeWorkspace->getKey(), 'site_id' => $otherSite->getKey(), 'requested_by' => $nativeRequesterId,
            'token_hash' => hash('sha256', 'other-token'), 'filters' => ['days' => 30], 'status' => 'completed',
            'expires_at' => now()->addDay(),
        ]);
        $otherExport->update(['file_path' => 'exports/'.$otherExport->getKey().'.csv']);
        $targetExport = ReportExport::query()->create([
            'workspace_id' => $nativeWorkspace->getKey(), 'site_id' => $site->getKey(), 'requested_by' => $nativeRequesterId,
            'token_hash' => hash('sha256', 'target-token'), 'filters' => ['days' => 30], 'status' => 'completed',
            'file_path' => $otherExport->file_path, 'expires_at' => now()->addDay(),
        ]);
        Storage::fake('analytics-local');
        Storage::disk('analytics-local')->put($otherExport->file_path, 'other site private report');

        $outcome = app(AnalyticsSiteDeletionService::class)->request($actor, $site, $site->slug,
            (string) $actor->workspaceMemberships()->value('workspace_id'), (string) $actor->getKey());

        $this->assertSame('waiting', $outcome->status);
        $this->assertSame('export_manifest_unavailable', $outcome->reasonCode);
        $this->assertTrue(Storage::disk('analytics-local')->exists($otherExport->file_path));
        $this->assertDatabaseHas('report_exports', ['id' => $targetExport->getKey()], 'analytics');
        $this->assertFalse($site->fresh()->trashed());
    }

    /** @return array{PlatformUser, AnalyticsWorkspace, Site} */
    private function mappedOwnerFixture(): array
    {
        config(['platform.products.analytics.auth_authority' => 'core', 'analytics.plan_authority' => 'legacy']);
        $actor = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(), 'name' => 'Owner', 'email' => 'analytics-delete@example.test',
            'email_normalized' => 'analytics-delete@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $coreWorkspace = CoreWorkspace::query()->create([
            'owner_user_id' => $actor->getKey(), 'name' => 'Delete workspace', 'slug' => 'delete-'.Str::lower(Str::random(8)), 'status' => 'active',
        ]);
        $membershipId = (string) Str::ulid();
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId, 'workspace_id' => $coreWorkspace->getKey(), 'user_id' => $actor->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(), 'membership_id' => $membershipId, 'product' => 'analytics', 'role' => 'owner',
            'status' => 'active', 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $nativeUser = AnalyticsUser::query()->forceCreate([
            'name' => $actor->name, 'email' => 'native-delete@example.test', 'password' => 'hashed', 'platform_user_id' => $actor->getKey(),
        ]);
        $nativeWorkspace = AnalyticsWorkspace::query()->create(['name' => 'Native delete workspace']);
        $nativeWorkspace->users()->attach($nativeUser, ['role' => WorkspaceRole::Owner->value]);
        $site = $nativeWorkspace->sites()->create([
            'name' => 'Delete site', 'slug' => 'delete-site-'.Str::lower(Str::random(5)), 'domains' => ['delete.example.test'], 'timezone' => 'UTC',
        ]);
        foreach ([
            ['user', (string) $nativeUser->getKey(), 'user', (string) $actor->getKey()],
            ['workspace', (string) $nativeWorkspace->getKey(), 'workspace', (string) $coreWorkspace->getKey()],
        ] as [$sourceEntity, $sourceId, $canonicalEntity, $canonicalId]) {
            LegacyIdentityMap::query()->create([
                'source_product' => 'analytics', 'source_entity' => $sourceEntity, 'source_id' => $sourceId,
                'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId, 'status' => 'reconciled',
                'batch_key' => (string) Str::uuid(),
            ]);
        }

        return [$actor, $nativeWorkspace, $site];
    }

    /** @return array{CoreWorkspace, AnalyticsWorkspace} */
    private function addMappedOwnerWorkspace(PlatformUser $actor, AnalyticsUser $nativeUser): array
    {
        $coreWorkspace = CoreWorkspace::query()->create([
            'owner_user_id' => $actor->getKey(), 'name' => 'Other delete workspace',
            'slug' => 'other-delete-'.Str::lower(Str::random(8)), 'status' => 'active',
        ]);
        $membershipId = (string) Str::ulid();
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId, 'workspace_id' => $coreWorkspace->getKey(), 'user_id' => $actor->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(), 'membership_id' => $membershipId, 'product' => 'analytics', 'role' => 'owner',
            'status' => 'active', 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $nativeWorkspace = AnalyticsWorkspace::query()->create(['name' => 'Other native delete workspace']);
        $nativeWorkspace->users()->attach($nativeUser, ['role' => WorkspaceRole::Owner->value]);
        LegacyIdentityMap::query()->create([
            'source_product' => 'analytics', 'source_entity' => 'workspace', 'source_id' => (string) $nativeWorkspace->getKey(),
            'canonical_entity' => 'workspace', 'canonical_id' => (string) $coreWorkspace->getKey(),
            'status' => 'reconciled', 'batch_key' => (string) Str::uuid(),
        ]);

        return [$coreWorkspace, $nativeWorkspace];
    }

    /** @param class-string<Command> $commandClass */
    private function registerAnalyticsConsoleCommand(string $commandClass): void
    {
        app(Kernel::class)->registerCommand(app($commandClass));
    }
}
