<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsIngestionBatch;
use App\Models\AnalyticsSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_command_removes_expired_detail(): void
    {
        $site = AnalyticsSite::factory()->create();
        $batch = AnalyticsIngestionBatch::create(['site_id' => $site->id, 'batch_id' => (string) Str::uuid(), 'event_count' => 1, 'status' => 'processed', 'accepted_at' => now()->subDays(100), 'processed_at' => now()->subDays(100)]);
        $event = AnalyticsEvent::create(['site_id' => $site->id, 'ingestion_batch_id' => $batch->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subDays(100), 'received_at' => now()->subDays(100), 'path' => '/', 'visitor_hash' => 'old']);
        $recent = AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subDay(), 'received_at' => now()->subDay(), 'path' => '/', 'visitor_hash' => 'new']);

        $this->assertSame(0, Artisan::call('analytics:prune'));

        $this->assertDatabaseMissing('analytics_events', ['id' => $event->id]);
        $this->assertDatabaseMissing('analytics_ingestion_batches', ['id' => $batch->id]);
        $this->assertDatabaseHas('analytics_events', ['id' => $recent->id]);
    }
}
