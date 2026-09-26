<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use App\Modules\Analytics\Services\Deletion\AnalyticsProductDeletionProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

final class ProductDeletionProviderTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_workspace_preview_counts_soft_deleted_sites_without_mutating_native_data(): void
    {
        [$owner, $workspace, $site] = $this->workspace();
        AnalyticsEvent::query()->create([
            'site_id' => $site->getKey(),
            'event_id' => (string) Str::uuid(),
            'type' => 'pageview',
            'occurred_at' => now(),
            'received_at' => now(),
            'path' => '/pricing',
        ]);
        $site->delete();

        $preview = app(AnalyticsProductDeletionProvider::class)->inspect(new ProductDeletionTarget(
            product: 'analytics',
            kind: 'workspace',
            sourceId: (string) $workspace->getKey(),
            actorSourceId: (string) $owner->getKey(),
            canonicalId: (string) Str::ulid(),
            actorId: (string) Str::ulid(),
        ));

        $this->assertSame([], $preview->blockers);
        $this->assertSame(1, $preview->counts['sites']);
        $this->assertSame(1, $preview->counts['events']);
        $this->assertDatabaseHas('sites', ['id' => $site->getKey()], 'analytics');
        $this->assertDatabaseMissing('analytics_deletion_tombstones', ['source_id' => $workspace->getKey()], 'analytics');
    }

    public function test_account_preview_blocks_an_owned_workspace_outside_the_confirmed_scope(): void
    {
        [$owner, $workspace] = $this->workspace();
        $target = new ProductDeletionTarget(
            product: 'analytics',
            kind: 'account',
            sourceId: (string) $owner->getKey(),
            actorSourceId: (string) $owner->getKey(),
            canonicalId: (string) Str::ulid(),
            actorId: (string) Str::ulid(),
            sourceWorkspaceIds: [],
        );

        $preview = app(AnalyticsProductDeletionProvider::class)->inspect($target);

        $this->assertContains('workspace_scope_incomplete', $preview->blockers);
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->getKey()], 'analytics');
    }

    public function test_workspace_preview_blocks_native_teammates(): void
    {
        [$owner, $workspace] = $this->workspace();
        $teammate = User::factory()->create();
        $workspace->users()->attach($teammate, ['role' => WorkspaceRole::Viewer->value]);

        $preview = app(AnalyticsProductDeletionProvider::class)->inspect(new ProductDeletionTarget(
            product: 'analytics',
            kind: 'workspace',
            sourceId: (string) $workspace->getKey(),
            actorSourceId: (string) $owner->getKey(),
            canonicalId: (string) Str::ulid(),
            actorId: (string) Str::ulid(),
        ));

        $this->assertContains('workspace_has_teammates', $preview->blockers);
        $this->assertDatabaseMissing('analytics_deletion_tombstones', ['source_id' => $workspace->getKey()], 'analytics');
    }

    public function test_account_preview_blocks_exports_owned_in_a_foreign_workspace(): void
    {
        [$owner, $includedWorkspace] = $this->workspace();
        $foreignOwner = User::factory()->create();
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign export workspace']);
        $foreignWorkspace->users()->attach($foreignOwner, ['role' => WorkspaceRole::Owner->value]);
        $foreignSite = Site::query()->create([
            'workspace_id' => $foreignWorkspace->getKey(), 'name' => 'Foreign site', 'slug' => 'foreign-site',
            'domains' => ['foreign.example.test'], 'timezone' => 'UTC', 'verification_token' => Str::random(48),
            'collection_enabled' => true,
        ]);
        ReportExport::query()->create([
            'workspace_id' => $foreignWorkspace->getKey(), 'site_id' => $foreignSite->getKey(),
            'requested_by' => $owner->getKey(), 'token_hash' => hash('sha256', (string) Str::uuid()),
            'status' => 'completed', 'expires_at' => now()->addDay(),
        ]);

        $preview = app(AnalyticsProductDeletionProvider::class)->inspect(new ProductDeletionTarget(
            product: 'analytics', kind: 'account', sourceId: (string) $owner->getKey(),
            actorSourceId: (string) $owner->getKey(), canonicalId: (string) Str::ulid(), actorId: (string) Str::ulid(),
            sourceWorkspaceIds: [(string) $includedWorkspace->getKey()],
        ));

        $this->assertContains('foreign_report_exports', $preview->blockers);
        $this->assertDatabaseHas('report_exports', ['requested_by' => $owner->getKey(), 'workspace_id' => $foreignWorkspace->getKey()], 'analytics');
        $this->assertDatabaseMissing('analytics_deletion_tombstones', ['kind' => 'account', 'source_id' => $owner->getKey()], 'analytics');
    }

    public function test_workspace_fence_revokes_native_access_before_cleanup(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->assertTrue(app(AnalyticsWorkspaceAccess::class)->hasAccess($owner, $workspace));
        DB::connection('analytics')->table('analytics_deletion_tombstones')->insert([
            'kind' => 'workspace',
            'source_id' => (string) $workspace->getKey(),
            'canonical_id' => (string) Str::ulid(),
            'request_id' => (string) Str::ulid(),
            'prepare_step_id' => (string) Str::ulid(),
            'payload_hash' => str_repeat('a', 64),
            'status' => 'fencing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(app(AnalyticsWorkspaceAccess::class)->hasAccess($owner, $workspace));
    }

    /** @return array{User, Workspace, Site} */
    private function workspace(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Deletion fixture']);
        $workspace->users()->attach($owner, ['role' => WorkspaceRole::Owner->value]);
        $site = Site::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Fixture site',
            'slug' => 'fixture-site',
            'domains' => ['fixture.example.test'],
            'timezone' => 'UTC',
            'verification_token' => Str::random(48),
            'collection_enabled' => true,
        ]);

        return [$owner, $workspace, $site];
    }
}
