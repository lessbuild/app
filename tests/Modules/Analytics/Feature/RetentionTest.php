<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Modules\Analytics\Database\Factories\UserFactory;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\Invitation;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_prune_command_removes_expired_detail_and_access_records(): void
    {
        $user = UserFactory::new()->create();
        $workspace = Workspace::create(['name' => 'Analytics workspace']);
        $workspace->users()->attach($user, ['role' => WorkspaceRole::Owner->value]);
        $site = $workspace->sites()->create(['name' => 'Example site', 'domains' => ['example.com'], 'timezone' => 'UTC']);
        $batch = IngestionBatch::create(['site_id' => $site->id, 'batch_id' => (string) Str::uuid(), 'event_count' => 1, 'status' => 'processed', 'accepted_at' => now()->subDays(100), 'processed_at' => now()->subDays(100)]);
        $event = AnalyticsEvent::create(['site_id' => $site->id, 'ingestion_batch_id' => $batch->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subDays(100), 'received_at' => now()->subDays(100), 'path' => '/', 'visitor_hash' => 'old']);
        Invitation::create(['workspace_id' => $workspace->id, 'invited_by' => $user->id, 'email' => 'old@example.com', 'role' => 'viewer', 'token_hash' => hash('sha256', 'old'), 'expires_at' => now()->subDay()]);

        $this->artisan('analytics:prune')->assertSuccessful();

        $this->assertDatabaseMissing('analytics_events', ['id' => $event->id], 'analytics');
        $this->assertDatabaseMissing('ingestion_batches', ['id' => $batch->id], 'analytics');
        $this->assertDatabaseCount('invitations', 0, 'analytics');
    }

    public function test_core_plan_retention_is_workspace_scoped_and_unmapped_workspaces_keep_data(): void
    {
        config([
            'analytics.plan_authority' => 'core',
            'platform.billing.entitled_statuses.analytics' => ['active', 'trialing'],
        ]);

        $user = UserFactory::new()->create();
        $mappedWorkspace = Workspace::create(['name' => 'Mapped workspace']);
        $unmappedWorkspace = Workspace::create(['name' => 'Unmapped workspace']);
        $mappedWorkspace->users()->attach($user, ['role' => WorkspaceRole::Owner->value]);
        $mappedSite = $mappedWorkspace->sites()->create([
            'name' => 'Mapped site',
            'domains' => ['mapped.example.test'],
            'timezone' => 'UTC',
        ]);
        $unmappedSite = $unmappedWorkspace->sites()->create([
            'name' => 'Unmapped site',
            'domains' => ['unmapped.example.test'],
            'timezone' => 'UTC',
        ]);

        $ownerUserId = (string) Str::ulid();
        DB::connection('core')->table('users')->insert([
            'id' => $ownerUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $coreWorkspace = CoreWorkspace::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'owner_user_id' => $ownerUserId,
            'name' => 'Mapped core workspace',
            'slug' => 'mapped-core-workspace',
            'status' => 'active',
        ]);
        LegacyIdentityMap::query()->create([
            'source_product' => 'analytics',
            'source_entity' => 'workspace',
            'source_id' => (string) $mappedWorkspace->getKey(),
            'canonical_entity' => 'workspace',
            'canonical_id' => $coreWorkspace->getKey(),
            'status' => 'reconciled',
            'batch_key' => 'analytics-workspace-site-import-v1',
            'metadata' => [],
        ]);
        $subscription = ProductSubscription::query()->create([
            'workspace_id' => $coreWorkspace->getKey(),
            'product' => 'analytics',
            'provider' => 'legacy_access',
            'provider_account_key' => 'analytics',
            'plan_key' => 'legacy_access',
            'status' => 'active',
            'quantity' => 1,
            'metadata' => ['plan_snapshot' => [
                'name' => 'Existing Analytics access',
                'entitlements' => ['*'],
                'limits' => [
                    'retention_days' => 7,
                    'aggregate_retention_months' => 2,
                    'export_retention_hours' => 24,
                ],
            ]],
        ]);
        CurrentProductSubscription::query()->create([
            'workspace_id' => $coreWorkspace->getKey(),
            'product' => 'analytics',
            'product_subscription_id' => $subscription->getKey(),
        ]);

        $expiredMappedEvent = $this->addEvent($mappedSite->getKey(), now()->subDays(10));
        $recentMappedEvent = $this->addEvent($mappedSite->getKey(), now()->subDays(5));
        $unmappedEvent = $this->addEvent($unmappedSite->getKey(), now()->subDays(30));

        $this->artisan('analytics:prune')
            ->expectsOutputToContain('skipped 1 unmapped or unavailable workspaces')
            ->assertSuccessful();

        $this->assertDatabaseMissing('analytics_events', ['id' => $expiredMappedEvent->getKey()], 'analytics');
        $this->assertDatabaseHas('analytics_events', ['id' => $recentMappedEvent->getKey()], 'analytics');
        $this->assertDatabaseHas('analytics_events', ['id' => $unmappedEvent->getKey()], 'analytics');

    }

    private function addEvent(int $siteId, Carbon $occurredAt): AnalyticsEvent
    {
        return AnalyticsEvent::create([
            'site_id' => $siteId,
            'event_id' => (string) Str::uuid(),
            'type' => 'pageview',
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
            'path' => '/',
            'visitor_hash' => 'visitor-'.$siteId.'-'.$occurredAt->timestamp,
        ]);
    }
}
