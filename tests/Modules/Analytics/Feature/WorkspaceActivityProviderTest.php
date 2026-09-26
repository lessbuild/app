<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\WorkspaceActivityProviderRegistry;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

final class WorkspaceActivityProviderTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_exports_and_ingestion_batches_are_scoped_to_the_mapped_project_workspace_and_permissions(): void
    {
        config(['platform.products.analytics.auth_authority' => 'core']);

        $user = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'name' => 'Analytics owner',
            'email' => 'analytics-owner@example.test',
            'email_normalized' => 'analytics-owner@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
        ]);
        $coreWorkspace = $this->coreWorkspace($user, 'Analytics projects');
        $membershipId = (string) Str::ulid();
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $coreWorkspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $membershipId,
            'product' => 'analytics',
            'role' => 'owner',
            'status' => 'active',
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $project = Project::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'workspace_id' => $coreWorkspace->getKey(),
            'created_by_user_id' => $user->getKey(),
            'name' => 'Storefront',
            'slug' => 'storefront',
            'status' => 'active',
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $project->getKey(),
            'user_id' => $user->getKey(),
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ProjectProduct::query()->create([
            'project_id' => $project->getKey(),
            'product' => 'analytics',
            'status' => 'active',
        ]);

        $analyticsUser = AnalyticsUser::factory()->create();
        $mappedWorkspace = AnalyticsWorkspace::create(['name' => 'Mapped Analytics workspace']);
        $mappedWorkspace->users()->attach($analyticsUser, ['role' => WorkspaceRole::Owner->value]);
        $this->identityMap('user', $analyticsUser->getKey(), 'user', $user->getKey());
        $this->identityMap('workspace', $mappedWorkspace->getKey(), 'workspace', $coreWorkspace->getKey());
        $mappedSite = $mappedWorkspace->sites()->create([
            'name' => 'Mapped site',
            'domains' => ['mapped.example'],
            'timezone' => 'UTC',
        ]);
        ProjectResource::query()->create([
            'project_id' => $project->getKey(),
            'product' => 'analytics',
            'resource_type' => 'site',
            'resource_id' => (string) $mappedSite->getKey(),
            'name' => $mappedSite->name,
            'status' => 'active',
        ]);
        $mappedExport = $this->export($mappedSite);
        $batchId = (string) Str::uuid();
        $mappedBatch = $mappedSite->ingestionBatches()->create([
            'batch_id' => $batchId,
            'event_count' => 4,
            'status' => 'failed',
            'accepted_at' => now(),
            'processed_at' => now(),
            'failure_message' => 'private analytics processing exception',
        ]);

        $otherCoreWorkspace = $this->coreWorkspace($user, 'Other project workspace');
        $otherAnalyticsWorkspace = AnalyticsWorkspace::create(['name' => 'Other Analytics workspace']);
        $otherAnalyticsWorkspace->users()->attach($analyticsUser, ['role' => WorkspaceRole::Owner->value]);
        $this->identityMap('workspace', $otherAnalyticsWorkspace->getKey(), 'workspace', $otherCoreWorkspace->getKey());
        $otherSite = $otherAnalyticsWorkspace->sites()->create([
            'name' => 'Other site',
            'domains' => ['other.example'],
            'timezone' => 'UTC',
        ]);
        ProjectResource::query()->create([
            'project_id' => $project->getKey(),
            'product' => 'analytics',
            'resource_type' => 'site',
            'resource_id' => (string) $otherSite->getKey(),
            'name' => $otherSite->name,
            'status' => 'active',
        ]);
        $this->export($otherSite, 'completed');
        $otherSite->ingestionBatches()->create([
            'batch_id' => (string) Str::uuid(),
            'event_count' => 12,
            'status' => 'processed',
            'accepted_at' => now(),
            'processed_at' => now(),
        ]);

        $snapshot = app(WorkspaceActivityProviderRegistry::class)
            ->get('analytics')
            ->recentForWorkspace($user, $coreWorkspace, collect([$project]), 30);

        $this->assertTrue($snapshot->available);
        $this->assertCount(2, $snapshot->runs);

        $exportRun = $snapshot->runs->sole(fn ($run) => $run->key === 'analytics:report-export:'.$mappedExport->getKey());
        $this->assertSame('Storefront', $exportRun->projectName);
        $this->assertCount(1, $exportRun->steps);
        $this->assertInstanceOf(ProjectWorkflowStep::class, $exportRun->steps[0]);
        $this->assertSame(ProjectWorkflowStepState::Pending, $exportRun->steps[0]->state);
        $this->assertStringContainsString('/sites/'.$mappedSite->getKey().'/exports/'.$mappedExport->getKey().'/record', $exportRun->steps[0]->resultUrl);

        $batchRun = $snapshot->runs->sole(fn ($run) => $run->key === 'analytics:ingestion-batch:'.$mappedBatch->getKey());
        $this->assertSame('Storefront', $batchRun->projectName);
        $this->assertSame(ProjectWorkflowStepState::Failed, $batchRun->steps[0]->state);
        $this->assertStringContainsString('4 accepted events', $batchRun->steps[0]->detail);
        $this->assertStringNotContainsString($batchId, $batchRun->steps[0]->detail.$batchRun->steps[0]->resultUrl);
        $this->assertStringNotContainsString('private analytics processing exception', $batchRun->steps[0]->detail);
        $this->assertStringContainsString('/dashboard?site='.$mappedSite->getKey(), $batchRun->steps[0]->resultUrl);
    }

    private function coreWorkspace(PlatformUser $user, string $name): CoreWorkspace
    {
        return CoreWorkspace::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'owner_user_id' => $user->getKey(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => 'active',
        ]);
    }

    private function identityMap(string $sourceEntity, string|int $sourceId, string $canonicalEntity, string|int $canonicalId): void
    {
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'analytics',
            'source_entity' => $sourceEntity,
            'source_id' => (string) $sourceId,
            'canonical_entity' => $canonicalEntity,
            'canonical_id' => (string) $canonicalId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function export(Site $site, string $status = 'pending'): ReportExport
    {
        return ReportExport::create([
            'workspace_id' => $site->workspace_id,
            'site_id' => $site->getKey(),
            'requested_by' => $site->workspace->users()->value('users.id'),
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'status' => $status,
            'filters' => ['days' => 30],
            'expires_at' => now()->addHour(),
            'completed_at' => $status === 'completed' ? now() : null,
        ]);
    }
}
