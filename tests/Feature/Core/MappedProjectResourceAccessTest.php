<?php

namespace Tests\Feature\Core;

use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Identity\MappedProjectResourceAccess;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MappedProjectResourceAccessTest extends TestCase
{
    private PlatformUser $user;

    private Workspace $workspace;

    private Project $project;

    private WorkspaceMembership $membership;

    private MappedProjectResourceAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.core.database' => ':memory:']);
        DB::purge('core');
        Artisan::call('platform:migrate', ['module' => 'core']);
        foreach (['deployer', 'monitor', 'analytics'] as $product) {
            config(["platform.products.{$product}.auth_authority" => 'core']);
        }

        $this->user = PlatformUser::query()->forceCreate([
            'name' => 'Project member', 'email' => 'member@example.test',
            'email_normalized' => 'member@example.test', 'status' => 'active',
        ]);
        $this->workspace = Workspace::query()->create([
            'owner_user_id' => $this->user->getKey(), 'name' => 'Team',
            'slug' => 'team', 'status' => 'active',
        ]);
        $this->membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->user->getKey(),
            'role' => 'owner', 'status' => 'active',
        ]);
        $this->project = $this->makeProject($this->workspace);

        foreach (['deployer', 'monitor', 'analytics'] as $product) {
            WorkspaceProductAccess::query()->create([
                'membership_id' => $this->membership->getKey(), 'product' => $product,
                'role' => 'owner', 'status' => 'active',
            ]);
            $this->identity($product, $product === 'deployer' ? 'organization' : 'workspace', '10', 'workspace', $this->workspace->getKey());
        }
        $this->access = app(MappedProjectResourceAccess::class);
    }

    public function test_mapped_project_revocation_is_rechecked_without_revoking_local_only_resources(): void
    {
        $this->resource('analytics', 'site', '1');
        $this->assertTrue($this->allowed('analytics', 'site', '1'));
        $this->assertTrue($this->allowed('analytics', 'site', '2'));

        ProjectMembership::query()->where('project_id', $this->project->getKey())->update(['revoked_at' => now()]);

        $this->assertFalse($this->allowed('analytics', 'site', '1'));
        $this->assertTrue($this->allowed('analytics', 'site', '2'));
        $this->assertSame(['1'], $this->access->deniedResourceIds($this->user, 'analytics', 'site', 'workspace', '10', [1, 2]));
    }

    public function test_core_context_must_remain_active_even_for_unmapped_resources(): void
    {
        $this->assertTrue($this->allowed('monitor', 'application', '1'));
        $this->membership->update(['expires_at' => now()->subSecond()]);
        $this->assertNull($this->access->deniedResourceIds($this->user, 'monitor', 'application', 'workspace', '10', [1]));
        $this->membership->update(['expires_at' => null]);

        WorkspaceProductAccess::query()->where('product', 'monitor')->update(['revoked_at' => now()]);
        $this->assertFalse($this->allowed('monitor', 'application', '1'));
        WorkspaceProductAccess::query()->where('product', 'monitor')->update(['revoked_at' => null]);

        PlatformUser::query()->whereKey($this->user->getKey())->update(['status' => 'suspended']);
        $this->assertSame('active', $this->user->status);
        $this->assertFalse($this->allowed('monitor', 'application', '1'));
    }

    public function test_stale_workspace_mapping_denies_the_whole_native_query(): void
    {
        LegacyIdentityMap::query()->where('source_product', 'deployer')->where('source_entity', 'organization')->update(['status' => 'needs_review']);

        $this->assertNull($this->access->deniedResourceIds($this->user, 'deployer', 'project', 'organization', '10', []));
    }

    public function test_legacy_authority_preserves_product_owned_permissions_without_core_context(): void
    {
        config(['platform.products.deployer.auth_authority' => 'legacy']);

        $this->assertTrue($this->access->allows(new GenericUser(['id' => 999]), 'deployer', 'project', '1', 'organization', 'missing'));
    }

    public function test_legacy_only_project_mapping_is_enforced_and_pending_maps_are_not_unmapped(): void
    {
        $map = $this->identity('deployer', 'project', '1', 'project', $this->project->getKey());
        $this->assertTrue($this->allowed('deployer', 'project', '1'));
        $map->update(['status' => 'pending']);
        $this->assertFalse($this->allowed('deployer', 'project', '1'));
        $map->update(['status' => 'reconciled', 'canonical_id' => (string) Str::ulid()]);
        $this->assertFalse($this->allowed('deployer', 'project', '1'));
    }

    public function test_disagreeing_project_maps_are_denied_even_when_both_projects_are_accessible(): void
    {
        $this->resource('analytics', 'site', '1');
        $other = $this->makeProject($this->workspace);
        $this->identity('analytics', 'site', '1', 'project', $other->getKey());

        $this->assertFalse($this->allowed('analytics', 'site', '1'));
    }

    public function test_a_resource_cannot_borrow_membership_from_another_workspace(): void
    {
        $other = Workspace::query()->create([
            'owner_user_id' => $this->user->getKey(), 'name' => 'Other', 'slug' => 'other', 'status' => 'active',
        ]);
        $project = $this->makeProject($other);
        $resource = $this->resource('monitor', 'application', '1');
        $resource->update(['project_id' => $project->getKey()]);

        $this->assertFalse($this->allowed('monitor', 'application', '1'));
    }

    public function test_resource_and_project_lifecycles_do_not_bypass_membership(): void
    {
        $resource = $this->resource('analytics', 'site', '1');
        $resource->update(['status' => 'paused']);
        $this->assertTrue($this->allowed('analytics', 'site', '1'));
        $resource->update(['status' => 'archived']);
        $this->assertFalse($this->allowed('analytics', 'site', '1'));
        $resource->update(['status' => 'active']);
        ProjectProduct::query()->where('project_id', $this->project->getKey())->where('product', 'analytics')->update(['status' => 'inactive']);
        $this->assertFalse($this->allowed('analytics', 'site', '1'));
        ProjectProduct::query()->where('project_id', $this->project->getKey())->where('product', 'analytics')->update(['status' => 'active']);
        $this->project->update(['archived_at' => now()]);
        $this->assertFalse($this->allowed('analytics', 'site', '1'));
    }

    public function test_paused_monitor_environment_remains_manageable_but_stale_or_conflicting_environment_maps_deny(): void
    {
        $environment = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'name' => 'Production', 'slug' => 'production', 'status' => 'paused',
        ]);
        $resource = $this->resource('monitor', 'environment', '1');
        $resource->update(['environment_id' => $environment->getKey(), 'status' => 'paused']);
        $identity = $this->identity('monitor', 'environment', '1', 'project_environment', $environment->getKey());
        $this->assertTrue($this->allowed('monitor', 'environment', '1'));
        $environment->update(['status' => 'archived']);
        $this->assertFalse($this->allowed('monitor', 'environment', '1'));
        $environment->update(['status' => 'active']);
        $other = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'name' => 'Staging', 'slug' => 'staging', 'status' => 'active',
        ]);
        $identity->update(['canonical_id' => $other->getKey()]);
        $this->assertFalse($this->allowed('monitor', 'environment', '1'));
    }

    public function test_native_user_identity_requires_an_explicit_reconciled_mapping(): void
    {
        $principal = new GenericUser(['id' => 55, 'email' => $this->user->email]);
        $this->assertFalse($this->access->allows($principal, 'analytics', 'site', '1', 'workspace', '10'));
        $this->identity('analytics', 'user', '55', 'user', $this->user->getKey());
        $this->assertTrue($this->access->allows($principal, 'analytics', 'site', '1', 'workspace', '10'));
    }

    public function test_legacy_environment_mapping_cannot_substitute_project_authority_for_environment_authority(): void
    {
        $this->identity('monitor', 'environment', '1', 'project', $this->project->getKey());

        $this->assertFalse($this->allowed('monitor', 'environment', '1'));
    }

    public function test_deleting_a_canonical_environment_does_not_turn_its_resource_into_an_unmapped_environment(): void
    {
        $environment = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(),
            'name' => 'Production',
            'slug' => 'production',
            'status' => 'active',
        ]);
        $resource = $this->resource('monitor', 'environment', '1');
        $resource->update(['environment_id' => $environment->getKey()]);
        $this->assertTrue($this->allowed('monitor', 'environment', '1'));

        $environment->delete();

        $this->assertNull($resource->fresh()->environment_id);
        $this->assertFalse($this->allowed('monitor', 'environment', '1'));
        $this->assertTrue($this->allowed('monitor', 'environment', '2'), 'An unrelated environment without any Core mapping retains native access.');
    }

    public function test_bulk_checks_cross_the_candidate_chunk_boundary_without_queries_per_project(): void
    {
        $now = now();
        $rows = [
            'projects' => [],
            'project_memberships' => [],
            'project_products' => [],
            'project_resources' => [],
            'legacy_identity_maps' => [],
        ];
        $candidateIds = [];

        for ($index = 0; $index < 505; $index++) {
            $projectId = (string) Str::ulid();
            $sourceId = (string) (1000 + $index);
            $candidateIds[] = $sourceId;
            $rows['projects'][] = [
                'id' => $projectId,
                'workspace_id' => $this->workspace->getKey(),
                'name' => 'Mapped site '.$index,
                'slug' => 'mapped-site-'.$index,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $rows['project_memberships'][] = [
                'id' => (string) Str::ulid(),
                'project_id' => $projectId,
                'user_id' => $this->user->getKey(),
                'role' => 'member',
                'status' => $index === 504 ? 'revoked' : 'active',
                'revoked_at' => $index === 504 ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $rows['project_products'][] = [
                'id' => (string) Str::ulid(),
                'project_id' => $projectId,
                'product' => 'analytics',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $rows['project_resources'][] = [
                'id' => (string) Str::ulid(),
                'project_id' => $projectId,
                'product' => 'analytics',
                'resource_type' => 'site',
                'resource_id' => $sourceId,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $rows['legacy_identity_maps'][] = [
                'id' => (string) Str::ulid(),
                'source_product' => 'analytics',
                'source_entity' => 'site',
                'source_id' => $sourceId,
                'canonical_entity' => 'project',
                'canonical_id' => $projectId,
                'status' => 'reconciled',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $connection = DB::connection('core');
        foreach ($rows as $table => $tableRows) {
            foreach (array_chunk($tableRows, 50) as $chunk) {
                $connection->table($table)->insert($chunk);
            }
        }

        $connection->enableQueryLog();
        try {
            $connection->flushQueryLog();
            $this->assertSame([], $this->access->deniedResourceIds(
                $this->user, 'analytics', 'site', 'workspace', '10', array_slice($candidateIds, 0, 5),
            ));
            $smallQueryCount = count($connection->getQueryLog());

            $connection->flushQueryLog();
            $denied = $this->access->deniedResourceIds(
                $this->user, 'analytics', 'site', 'workspace', '10', $candidateIds,
            );
            $largeQueryCount = count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $this->assertSame(['1504'], $denied, 'A revoked mapping beyond the first 500 candidates must still be denied.');
        $this->assertLessThanOrEqual(
            $smallQueryCount + 20,
            $largeQueryCount,
            'Growing from 5 to 505 mapped projects should add batch queries, not queries for every project.',
        );
    }

    public function test_archived_data_can_be_exported_without_reopening_interactive_access(): void
    {
        $resource = $this->resource('analytics', 'site', '1');
        $resource->update(['status' => 'archived']);
        $this->identity('analytics', 'site', '1', 'project', $this->project->getKey());
        $this->project->update(['status' => 'archived', 'archived_at' => now()]);
        ProjectProduct::query()->where('project_id', $this->project->getKey())->where('product', 'analytics')->update(['status' => 'inactive']);

        $this->assertFalse($this->allowed('analytics', 'site', '1'));
        $this->assertTrue($this->historyAllowed('analytics', 'site', '1'));
        $this->assertSame([], $this->access->deniedResourceIds(
            $this->user, 'analytics', 'site', 'workspace', '10', ['1'],
            ProjectResourceAccessPurpose::HistoricalExport,
        ));
        $this->assertSame('archived', $resource->fresh()->status);
        $this->assertSame('archived', $this->project->fresh()->status);
        $this->assertDatabaseHas('project_products', [
            'project_id' => $this->project->getKey(), 'product' => 'analytics', 'status' => 'inactive',
        ], 'core');
    }

    public function test_historical_export_never_restores_revoked_membership_or_product_access(): void
    {
        $this->resource('analytics', 'site', '1')->update(['status' => 'archived']);
        $this->project->update(['status' => 'archived', 'archived_at' => now()]);
        $this->assertTrue($this->historyAllowed('analytics', 'site', '1'));

        $membership = ProjectMembership::query()->where('project_id', $this->project->getKey())->firstOrFail();
        $membership->update(['revoked_at' => now()]);
        $this->assertFalse($this->historyAllowed('analytics', 'site', '1'));
        $membership->update(['revoked_at' => null]);

        $grant = WorkspaceProductAccess::query()->where('membership_id', $this->membership->getKey())->where('product', 'analytics')->firstOrFail();
        $grant->update(['revoked_at' => now()]);
        $this->assertFalse($this->historyAllowed('analytics', 'site', '1'));
        $grant->update(['revoked_at' => null]);

        $this->membership->update(['expires_at' => now()->subSecond()]);
        $this->assertFalse($this->historyAllowed('analytics', 'site', '1'));
        $this->membership->update(['expires_at' => null]);
        $this->workspace->update(['status' => 'archived', 'archived_at' => now()]);
        $this->assertFalse($this->historyAllowed('analytics', 'site', '1'));
    }

    public function test_historical_environment_export_requires_the_exact_retained_environment_mapping(): void
    {
        $environment = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'name' => 'Production', 'slug' => 'production', 'status' => 'archived',
        ]);
        $resource = $this->resource('monitor', 'environment', '1');
        $resource->update(['environment_id' => $environment->getKey(), 'status' => 'archived']);
        $map = $this->identity('monitor', 'environment', '1', 'project_environment', $environment->getKey());
        $this->assertFalse($this->allowed('monitor', 'environment', '1'));
        $this->assertTrue($this->historyAllowed('monitor', 'environment', '1'));

        $map->update(['status' => 'needs_review']);
        $this->assertFalse($this->historyAllowed('monitor', 'environment', '1'));
        $map->update(['status' => 'reconciled']);
        $environment->delete();
        $this->assertNull($resource->fresh()->environment_id);
        $this->assertFalse($this->historyAllowed('monitor', 'environment', '1'));
    }

    public function test_historical_export_accepts_retained_inactive_products_but_not_missing_or_suspended_authority(): void
    {
        $resource = $this->resource('deployer', 'project', '1');
        $product = ProjectProduct::query()->where('project_id', $this->project->getKey())->where('product', 'deployer')->firstOrFail();
        $product->update(['status' => 'inactive']);
        $this->assertFalse($this->allowed('deployer', 'project', '1'));
        $this->assertTrue($this->historyAllowed('deployer', 'project', '1'));

        $resource->update(['status' => 'revoked']);
        $this->assertFalse($this->historyAllowed('deployer', 'project', '1'));
        $resource->update(['status' => 'active']);
        $this->project->update(['status' => 'suspended']);
        $this->assertFalse($this->historyAllowed('deployer', 'project', '1'));
        $this->project->update(['status' => 'active']);
        $product->delete();
        $this->assertFalse($this->historyAllowed('deployer', 'project', '1'));
    }

    public function test_retained_read_preserves_archived_details_while_restore_requires_an_explicitly_active_project(): void
    {
        $resource = $this->resource('monitor', 'application', '1');
        $resource->update(['status' => 'archived']);
        $this->identity('monitor', 'application', '1', 'project', $this->project->getKey());
        $product = ProjectProduct::query()->where('project_id', $this->project->getKey())->where('product', 'monitor')->firstOrFail();
        $product->update(['status' => 'inactive']);
        $this->project->update(['status' => 'archived', 'archived_at' => now()]);

        $this->assertTrue($this->lifecycleAllowed('application', '1', ProjectResourceAccessPurpose::RetainedRead));
        $this->assertFalse($this->lifecycleAllowed('application', '1', ProjectResourceAccessPurpose::Restoration));
        $this->assertFalse($this->allowed('monitor', 'application', '1'));
        $this->project->update(['status' => 'active']);
        $this->assertFalse($this->lifecycleAllowed('application', '1', ProjectResourceAccessPurpose::Restoration));
        $this->project->update(['archived_at' => null]);
        $this->assertTrue($this->lifecycleAllowed('application', '1', ProjectResourceAccessPurpose::Restoration));
        $this->assertSame('inactive', $product->fresh()->status);
        $this->assertSame('archived', $resource->fresh()->status);
    }

    public function test_retained_read_and_restore_both_require_current_memberships_grants_and_account_state(): void
    {
        $this->resource('monitor', 'application', '1')->update(['status' => 'archived']);
        $member = ProjectMembership::query()->where('project_id', $this->project->getKey())->firstOrFail();
        $grant = WorkspaceProductAccess::query()->where('membership_id', $this->membership->getKey())->where('product', 'monitor')->firstOrFail();

        foreach ([ProjectResourceAccessPurpose::RetainedRead, ProjectResourceAccessPurpose::Restoration] as $purpose) {
            $this->assertTrue($this->lifecycleAllowed('application', '1', $purpose));
            $member->update(['revoked_at' => now()]);
            $this->assertFalse($this->lifecycleAllowed('application', '1', $purpose));
            $member->update(['revoked_at' => null]);
            $grant->update(['expires_at' => now()->subSecond()]);
            $this->assertFalse($this->lifecycleAllowed('application', '1', $purpose));
            $grant->update(['expires_at' => null]);
            $this->membership->update(['status' => 'revoked']);
            $this->assertFalse($this->lifecycleAllowed('application', '1', $purpose));
            $this->membership->update(['status' => 'active']);
            PlatformUser::query()->whereKey($this->user->getKey())->update(['status' => 'inactive']);
            $this->assertFalse($this->lifecycleAllowed('application', '1', $purpose));
            PlatformUser::query()->whereKey($this->user->getKey())->update(['status' => 'active']);
            $this->workspace->update(['archived_at' => now()]);
            $this->assertFalse($this->lifecycleAllowed('application', '1', $purpose));
            $this->workspace->update(['archived_at' => null]);
        }
    }

    public function test_lifecycle_purposes_preserve_exact_environment_mapping_and_reject_deleted_targets(): void
    {
        $environment = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'name' => 'Retained', 'slug' => 'retained', 'status' => 'archived',
        ]);
        $resource = $this->resource('monitor', 'environment', '1');
        $resource->update(['environment_id' => $environment->getKey(), 'status' => 'archived']);
        $identity = $this->identity('monitor', 'environment', '1', 'project_environment', $environment->getKey());
        foreach ([ProjectResourceAccessPurpose::RetainedRead, ProjectResourceAccessPurpose::Restoration] as $purpose) {
            $this->assertTrue($this->lifecycleAllowed('environment', '1', $purpose));
            $identity->update(['status' => 'needs_review']);
            $this->assertFalse($this->lifecycleAllowed('environment', '1', $purpose));
            $identity->update(['status' => 'reconciled']);
        }
        $environment->delete();
        foreach ([ProjectResourceAccessPurpose::RetainedRead, ProjectResourceAccessPurpose::Restoration] as $purpose) {
            $this->assertFalse($this->lifecycleAllowed('environment', '1', $purpose));
        }
        $this->assertNull($resource->fresh()->environment_id);
    }

    public function test_lifecycle_purposes_never_admit_suspended_or_missing_project_products_or_revoked_resources(): void
    {
        $resource = $this->resource('monitor', 'application', '1');
        $product = ProjectProduct::query()->where('project_id', $this->project->getKey())->where('product', 'monitor')->firstOrFail();
        foreach ([ProjectResourceAccessPurpose::RetainedRead, ProjectResourceAccessPurpose::Restoration] as $purpose) {
            $product->update(['status' => 'inactive']);
            $resource->update(['status' => 'revoked']);
            $this->assertFalse($this->lifecycleAllowed('application', '1', $purpose));
            $resource->update(['status' => 'archived']);
            $product->update(['status' => 'suspended']);
            $this->assertFalse($this->lifecycleAllowed('application', '1', $purpose));
            $product->update(['status' => 'inactive']);
            $this->project->update(['status' => 'suspended']);
            $this->assertFalse($this->lifecycleAllowed('application', '1', $purpose));
            $this->project->update(['status' => 'active']);
        }
        $product->delete();
        foreach ([ProjectResourceAccessPurpose::RetainedRead, ProjectResourceAccessPurpose::Restoration] as $purpose) {
            $this->assertFalse($this->lifecycleAllowed('application', '1', $purpose));
        }
    }

    private function lifecycleAllowed(string $type, string $id, ProjectResourceAccessPurpose $purpose): bool
    {
        return $this->access->allows($this->user, 'monitor', $type, $id, 'workspace', '10', purpose: $purpose);
    }

    private function historyAllowed(string $product, string $type, string $id): bool
    {
        return $this->access->allows(
            $this->user, $product, $type, $id, $product === 'deployer' ? 'organization' : 'workspace', '10',
            purpose: ProjectResourceAccessPurpose::HistoricalExport,
        );
    }

    private function allowed(string $product, string $type, string $id): bool
    {
        return $this->access->allows($this->user, $product, $type, $id, $product === 'deployer' ? 'organization' : 'workspace', '10');
    }

    private function resource(string $product, string $type, string $id): ProjectResource
    {
        return ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'product' => $product,
            'resource_type' => $type, 'resource_id' => $id, 'status' => 'active',
        ]);
    }

    private function identity(string $product, string $entity, string $sourceId, string $canonicalEntity, string $canonicalId): LegacyIdentityMap
    {
        return LegacyIdentityMap::query()->create([
            'source_product' => $product, 'source_entity' => $entity, 'source_id' => $sourceId,
            'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId, 'status' => 'reconciled',
        ]);
    }

    private function makeProject(Workspace $workspace): Project
    {
        $project = Project::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'App', 'slug' => (string) Str::ulid(), 'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $project->getKey(), 'user_id' => $this->user->getKey(), 'role' => 'member', 'status' => 'active',
        ]);
        foreach (['deployer', 'monitor', 'analytics'] as $product) {
            ProjectProduct::query()->create(['project_id' => $project->getKey(), 'product' => $product, 'status' => 'active']);
        }

        return $project;
    }
}
