<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildGoalConversions;
use App\Actions\Analytics\RebuildReportAggregates;
use App\Actions\Analytics\RebuildSiteVisits;
use App\Actions\Analytics\RefreshRecentAggregates;
use App\Actions\Billing\RecordUsage;
use App\Jobs\Analytics\ProcessEventBatch;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsIngestionBatch;
use App\Models\AnalyticsSite;
use App\Models\UsageRecord;
use App\Queries\Analytics\AnalyticsReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AnalyticsCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_site_accepts_a_batch_and_processes_it(): void
    {
        Queue::fake();
        $site = $this->makeSite();
        $goal = $site->goals()->create([
            'name' => 'Demo requested',
            'kind' => 'event',
            'match_type' => 'exact',
            'match_value' => 'demo_requested',
            'active' => true,
        ]);

        $response = $this->withHeader('Origin', 'https://example.com')->postJson(
            "/api/v1/collect/{$site->public_id}",
            ['events' => [
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'pageview',
                    'path' => '/pricing?utm_source=newsletter',
                    'occurred_at' => now()->toIso8601String(),
                    'referrer_host' => 'google.com',
                    'utm_source' => 'newsletter',
                    'utm_medium' => 'email',
                    'utm_campaign' => 'launch',
                    'visitor' => 'visitor-1',
                    'device' => 'desktop',
                    'browser' => 'Chrome',
                    'os' => 'macOS',
                ],
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'event',
                    'path' => '/pricing',
                    'properties' => ['name' => 'demo_requested', 'plan' => 'pro'],
                    'visitor' => 'visitor-1',
                ],
            ]],
        );

        $response->assertAccepted()->assertJsonPath('accepted', 2);
        $this->assertDatabaseCount('analytics_events', 2);
        $this->assertDatabaseHas('analytics_ingestion_batches', [
            'site_id' => $site->id,
            'event_count' => 2,
            'status' => 'pending',
        ]);

        $batch = AnalyticsIngestionBatch::query()->where('site_id', $site->id)->sole();
        (new ProcessEventBatch($batch->id))->handle(app(RebuildSiteVisits::class), app(RebuildGoalConversions::class), app(RebuildReportAggregates::class), app(RecordUsage::class));

        $this->assertDatabaseHas('analytics_ingestion_batches', [
            'id' => $batch->id,
            'status' => 'processed',
        ]);
        $this->assertNotNull($site->refresh()->last_processed_at);
        // One pageview (the other event isn't one) counts against the plan's monthly allowance.
        $this->assertSame(1, (int) UsageRecord::query()->where('meter', 'analytics.pageviews')->sum('quantity'));
        // Today's daily totals are left to the ten-minute refresh rather than rebuilt by every batch.
        $this->assertDatabaseMissing('analytics_daily_aggregates', ['site_id' => $site->id]);
        $this->assertSame(1, app(RefreshRecentAggregates::class)->handle());
        $this->assertDatabaseHas('analytics_daily_aggregates', ['site_id' => $site->id, 'dimension' => 'all']);
        $this->assertDatabaseHas('analytics_goal_conversions', ['site_id' => $site->id, 'goal_id' => $goal->id]);
    }

    public function test_duplicate_event_ids_are_ignored(): void
    {
        $site = $this->makeSite();
        $eventId = (string) Str::uuid();
        $payload = ['events' => [[
            'id' => $eventId,
            'type' => 'pageview',
            'path' => '/',
            'visitor' => 'visitor-1',
        ]]];

        $this->postJson("/api/v1/collect/{$site->public_id}", $payload)->assertAccepted();
        $this->postJson("/api/v1/collect/{$site->public_id}", $payload)->assertAccepted();

        $this->assertDatabaseCount('analytics_events', 1);
        $this->assertDatabaseCount('analytics_ingestion_batches', 2);
        $this->assertDatabaseCount('analytics_visits', 1);
    }

    public function test_pending_events_are_not_reported_until_their_batch_is_processed(): void
    {
        $site = $this->makeSite();
        $batch = $site->ingestionBatches()->create([
            'batch_id' => (string) Str::uuid(),
            'event_count' => 1,
            'status' => 'pending',
            'accepted_at' => now(),
        ]);
        AnalyticsEvent::create([
            'site_id' => $site->id,
            'ingestion_batch_id' => $batch->id,
            'event_id' => (string) Str::uuid(),
            'type' => 'pageview',
            'occurred_at' => now(),
            'received_at' => now(),
            'path' => '/pending',
            'visitor_hash' => 'visitor-pending',
        ]);

        $report = app(AnalyticsReportQuery::class)->handle($site);
        $this->assertFalse($report['hasData']);
        $this->assertDatabaseCount('analytics_visits', 0);

        (new ProcessEventBatch($batch->id))->handle(app(RebuildSiteVisits::class), app(RebuildGoalConversions::class), app(RebuildReportAggregates::class), app(RecordUsage::class));

        $report = app(AnalyticsReportQuery::class)->handle($site);
        $this->assertTrue($report['hasData']);
        $this->assertDatabaseCount('analytics_visits', 1);
    }

    public function test_collector_uses_receipt_time_and_drops_unapproved_properties(): void
    {
        Queue::fake();
        $site = $this->makeSite();
        $eventId = (string) Str::uuid();

        $this->postJson("/api/v1/collect/{$site->public_id}", ['events' => [[
            'id' => $eventId,
            'type' => 'event',
            'path' => '/signup?email=private@example.com#fragment',
            'occurred_at' => now()->subYears(2)->toIso8601String(),
            'properties' => ['name' => 'signup_completed', 'email' => 'private@example.com'],
        ]]])->assertAccepted();

        $event = AnalyticsEvent::query()->where('event_id', $eventId)->sole();
        $this->assertSame(['name' => 'signup_completed'], $event->properties);
        $this->assertSame('/signup', $event->path);
        $this->assertTrue(CarbonImmutable::parse($event->occurred_at)->greaterThan(now()->subMinute()));
    }

    public function test_unverified_sites_and_unknown_origins_are_rejected(): void
    {
        $site = $this->makeSite(['verified_at' => null]);
        $payload = ['events' => [[
            'id' => (string) Str::uuid(),
            'type' => 'pageview',
            'path' => '/',
        ]]];

        $this->postJson("/api/v1/collect/{$site->public_id}", $payload)
            ->assertNotFound();

        $site->forceFill(['verified_at' => now()])->save();

        $this->withHeader('Origin', 'https://other.example')
            ->postJson("/api/v1/collect/{$site->public_id}", $payload)
            ->assertForbidden();
    }

    public function test_registered_origin_can_preflight_collection(): void
    {
        $site = $this->makeSite();

        $this->withHeader('Origin', 'https://example.com')
            ->optionsJson("/api/v1/collect/{$site->public_id}")
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
    }

    /** @param array<string, mixed> $attributes */
    private function makeSite(array $attributes = []): AnalyticsSite
    {
        return AnalyticsSite::factory()->create(array_merge(['name' => 'Example site', 'domains' => ['example.com'], 'timezone' => 'UTC', 'verified_at' => now()], $attributes));
    }
}
