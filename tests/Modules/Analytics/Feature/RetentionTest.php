<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\Invitation;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_command_removes_expired_detail_and_access_records(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Analytics workspace']);
        $workspace->users()->attach($user, ['role' => WorkspaceRole::Owner->value]);
        $site = $workspace->sites()->create(['name' => 'Example site', 'domains' => ['example.com'], 'timezone' => 'UTC']);
        $batch = IngestionBatch::create(['site_id' => $site->id, 'batch_id' => (string) Str::uuid(), 'event_count' => 1, 'status' => 'processed', 'accepted_at' => now()->subDays(100), 'processed_at' => now()->subDays(100)]);
        $event = AnalyticsEvent::create(['site_id' => $site->id, 'ingestion_batch_id' => $batch->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subDays(100), 'received_at' => now()->subDays(100), 'path' => '/', 'visitor_hash' => 'old']);
        Invitation::create(['workspace_id' => $workspace->id, 'invited_by' => $user->id, 'email' => 'old@example.com', 'role' => 'viewer', 'token_hash' => hash('sha256', 'old'), 'expires_at' => now()->subDay()]);

        $this->artisan('analytics:prune')->assertSuccessful();

        $this->assertDatabaseMissing('analytics_events', ['id' => $event->id]);
        $this->assertDatabaseMissing('ingestion_batches', ['id' => $batch->id]);
        $this->assertDatabaseCount('invitations', 0);
    }
}
