<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Actions\Collection\RebuildSiteVisits;
use App\Modules\Analytics\Actions\Goals\RebuildGoalConversions;
use App\Modules\Analytics\Actions\Reporting\RebuildReportAggregates;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Jobs\ProcessEventBatch;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Queries\Reporting\OverviewReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

class AnalyticsCollectionTest extends TestCase
{
    use RefreshAnalyticsDatabase;

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
        $this->assertDatabaseHas('ingestion_batches', [
            'site_id' => $site->id,
            'event_count' => 2,
            'status' => 'pending',
        ]);

        $batch = IngestionBatch::query()->where('site_id', $site->id)->sole();
        (new ProcessEventBatch($batch->id))->handle(app(RebuildSiteVisits::class), app(RebuildGoalConversions::class), app(RebuildReportAggregates::class));

        $this->assertDatabaseHas('ingestion_batches', [
            'id' => $batch->id,
            'status' => 'processed',
        ]);
        $this->assertNotNull($site->fresh()->last_processed_at);
        $this->assertDatabaseHas('report_daily_aggregates', ['site_id' => $site->id, 'dimension' => 'all']);
        $this->assertDatabaseHas('goal_conversions', ['site_id' => $site->id, 'goal_id' => $goal->id]);
    }

    public function test_duplicate_event_ids_are_ignored(): void
    {
        Queue::fake();
        $site = $this->makeSite();
        $eventId = (string) Str::uuid();
        $payload = ['events' => [[
            'id' => $eventId,
            'type' => 'pageview',
            'path' => '/',
            'visitor' => 'visitor-1',
        ]]];

        $this->postJson("/api/v1/collect/{$site->public_id}", $payload)
            ->assertAccepted()
            ->assertJsonPath('accepted', 1);
        $this->postJson("/api/v1/collect/{$site->public_id}", $payload)
            ->assertAccepted()
            ->assertJsonPath('accepted', 0)
            ->assertJsonPath('batch_id', null);

        $this->assertDatabaseCount('analytics_events', 1);
        $this->assertDatabaseCount('ingestion_batches', 1);
    }

    public function test_collection_rate_limit_returns_standard_retry_headers(): void
    {
        Queue::fake();
        Cache::flush();
        config(['analytics.collect_rate_per_minute' => 1]);
        $site = $this->makeSite();
        $payload = ['events' => [[
            'id' => (string) Str::uuid(),
            'type' => 'pageview',
            'path' => '/',
        ]]];

        $this->postJson("/api/v1/collect/{$site->public_id}", $payload)->assertAccepted();
        $this->postJson("/api/v1/collect/{$site->public_id}", $payload)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0');
    }

    public function test_collection_rejects_request_bodies_over_the_published_limit(): void
    {
        $site = $this->makeSite();

        $this->postJson("/api/v1/collect/{$site->public_id}", [
            'events' => [[
                'id' => (string) Str::uuid(),
                'type' => 'event',
                'path' => '/',
                'oversized' => str_repeat('x', 33000),
            ]],
        ])->assertStatus(413);
    }

    public function test_duplicate_event_ids_within_a_batch_count_only_inserted_events(): void
    {
        Queue::fake();
        $site = $this->makeSite();
        $eventId = (string) Str::uuid();
        $event = [
            'id' => $eventId,
            'type' => 'pageview',
            'path' => '/',
            'visitor' => 'visitor-1',
        ];

        $this->postJson("/api/v1/collect/{$site->public_id}", ['events' => [$event, $event]])
            ->assertAccepted()
            ->assertJsonPath('accepted', 1);

        $this->assertDatabaseCount('analytics_events', 1);
        $this->assertDatabaseCount('ingestion_batches', 1);
        $this->assertDatabaseHas('ingestion_batches', [
            'site_id' => $site->id,
            'event_count' => 1,
        ]);
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

        $report = app(OverviewReport::class)->for($site);
        $this->assertFalse($report['hasData']);
        $this->assertDatabaseCount('visits', 0);

        (new ProcessEventBatch($batch->id))->handle(app(RebuildSiteVisits::class), app(RebuildGoalConversions::class), app(RebuildReportAggregates::class));

        $report = app(OverviewReport::class)->for($site);
        $this->assertTrue($report['hasData']);
        $this->assertDatabaseCount('visits', 1);
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

    public function test_collector_hashes_the_site_scoped_visitor_and_preserves_the_anonymous_session_id(): void
    {
        config(['analytics.visitor_key' => 'analytics-test-visitor-key']);
        $site = $this->makeSite();
        $visitor = '17fc1c10-8460-43ef-8f5b-9ef3426d0171';
        $session = 'd4709106-1fc8-46f1-9b86-7610118d159d';
        $userAgent = 'Buildpusher browser test';
        $eventId = (string) Str::uuid();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->withHeader('User-Agent', $userAgent)
            ->postJson("/api/v1/collect/{$site->public_id}", ['events' => [[
                'id' => $eventId,
                'type' => 'pageview',
                'path' => '/',
                'visitor' => $visitor,
                'session' => $session,
            ]]])
            ->assertAccepted();

        $event = AnalyticsEvent::query()->where('event_id', $eventId)->sole();
        $localDate = CarbonImmutable::parse($event->received_at)->setTimezone($site->timezone)->toDateString();

        $this->assertSame($session, $event->session_id);
        $this->assertSame(
            hash_hmac('sha256', $visitor.'|203.0.113.20|'.$userAgent.'|'.$localDate, 'analytics-test-visitor-key'),
            $event->visitor_hash,
        );
        $this->assertNotSame($visitor, $event->visitor_hash);
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

        $site->update(['verified_at' => now()]);

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

    public function test_core_plan_authority_fails_closed_for_unmapped_workspaces(): void
    {
        $site = $this->makeSite();
        config(['analytics.plan_authority' => 'core']);

        $this->withHeader('Origin', 'https://example.com')
            ->postJson("/api/v1/collect/{$site->public_id}", ['events' => [[
                'id' => (string) Str::uuid(),
                'type' => 'pageview',
                'path' => '/',
            ]]])
            ->assertServiceUnavailable()
            ->assertHeader('Access-Control-Allow-Origin', '*');

        $this->assertDatabaseCount('analytics_events', 0);
    }

    /** @param array<string, mixed> $attributes */
    private function makeSite(array $attributes = []): Site
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Analytics workspace']);
        $workspace->users()->attach($user, ['role' => WorkspaceRole::Owner->value]);

        return $workspace->sites()->create(array_merge([
            'name' => 'Example site',
            'domains' => ['example.com'],
            'timezone' => 'UTC',
            'verified_at' => now(),
        ], $attributes));
    }
}
