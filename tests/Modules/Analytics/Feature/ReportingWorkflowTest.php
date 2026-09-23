<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Actions\Collection\RebuildSiteVisits;
use App\Modules\Analytics\Actions\Goals\RebuildGoalConversions;
use App\Modules\Analytics\Actions\Reporting\RebuildReportAggregates;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Jobs\GenerateReportExport;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\GoalConversion;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Queries\Reporting\OverviewReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

class ReportingWorkflowTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_visit_rebuild_uses_identity_and_inactivity_windows(): void
    {
        [$user, $site] = $this->site();
        $base = now()->subMinutes(90);
        foreach ([0, 10, 45, 50] as $index => $minutes) {
            AnalyticsEvent::create([
                'site_id' => $site->id,
                'event_id' => (string) Str::uuid(),
                'type' => 'pageview',
                'occurred_at' => $base->copy()->addMinutes($minutes),
                'received_at' => now(),
                'path' => $index % 2 === 0 ? '/home' : '/pricing',
                'visitor_hash' => 'visitor-1',
                'session_id' => 'session-1',
            ]);
        }

        app(RebuildSiteVisits::class)->handle($site);

        $this->assertDatabaseCount('visits', 2);
        $this->assertDatabaseHas('visits', ['site_id' => $site->id, 'pageviews' => 2, 'landing_path' => '/home', 'exit_path' => '/pricing']);
    }

    public function test_visit_rebuild_starts_a_new_visit_at_the_site_local_midnight(): void
    {
        [$user, $site] = $this->site();
        $site->update(['timezone' => 'America/New_York']);
        foreach ([
            CarbonImmutable::parse('2026-01-02 04:59:00', 'UTC'),
            CarbonImmutable::parse('2026-01-02 05:01:00', 'UTC'),
        ] as $index => $occurredAt) {
            AnalyticsEvent::create([
                'site_id' => $site->id,
                'event_id' => (string) Str::uuid(),
                'type' => 'pageview',
                'occurred_at' => $occurredAt,
                'received_at' => now(),
                'path' => '/midnight-'.$index,
                'visitor_hash' => 'visitor-timezone',
            ]);
        }

        app(RebuildSiteVisits::class)->handle($site);

        $this->assertDatabaseCount('visits', 2);
    }

    public function test_long_ranges_use_daily_aggregates_after_detail_retention(): void
    {
        [$user, $site] = $this->site();
        $occurredAt = now()->subDays(120);
        AnalyticsEvent::create([
            'site_id' => $site->id,
            'event_id' => (string) Str::uuid(),
            'type' => 'pageview',
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
            'path' => '/historic',
            'visitor_hash' => 'visitor-historic',
        ]);

        app(RebuildSiteVisits::class)->handle($site);
        app(RebuildReportAggregates::class)->handle($site);

        $summary = app(OverviewReport::class)->for($site, 365);

        $this->assertSame('1', $summary['metrics'][0]['value']);
        $this->assertSame('/historic', $summary['pages'][0]['label']);
    }

    public function test_overview_filters_apply_to_metrics_and_breakdowns_without_crossing_sites(): void
    {
        [, $site] = $this->site();
        $otherSite = $site->workspace->sites()->create([
            'name' => 'Unrelated site',
            'domains' => ['unrelated.example'],
            'timezone' => 'UTC',
            'verified_at' => now(),
        ]);

        foreach ([
            ['/pricing', 'newsletter', 'launch', 'Desktop', 'visitor-one', 'session-one'],
            ['/pricing', 'newsletter', 'launch', 'Mobile', 'visitor-two', 'session-two'],
            ['/home', 'google', 'launch', 'Desktop', 'visitor-three', 'session-three'],
            ['/pricing', 'newsletter', 'evergreen', 'Desktop', 'visitor-four', 'session-four'],
        ] as $index => [$path, $source, $campaign, $device, $visitor, $session]) {
            AnalyticsEvent::create([
                'site_id' => $site->id,
                'event_id' => (string) Str::uuid(),
                'type' => 'pageview',
                'occurred_at' => now()->subMinutes($index + 1),
                'received_at' => now(),
                'path' => $path,
                'utm_source' => $source,
                'utm_campaign' => $campaign,
                'device_category' => $device,
                'visitor_hash' => $visitor,
                'session_id' => $session,
            ]);
        }

        AnalyticsEvent::create([
            'site_id' => $otherSite->id,
            'event_id' => (string) Str::uuid(),
            'type' => 'pageview',
            'occurred_at' => now(),
            'received_at' => now(),
            'path' => '/pricing',
            'utm_source' => 'newsletter',
            'utm_campaign' => 'launch',
            'device_category' => 'Desktop',
            'visitor_hash' => 'other-visitor',
            'session_id' => 'other-session',
        ]);

        app(RebuildSiteVisits::class)->handle($site);

        $summary = app(OverviewReport::class)->for($site, 7, [
            'path' => '/pricing',
            'source' => 'newsletter',
            'campaign' => 'launch',
            'device' => 'Desktop',
        ]);

        $this->assertSame('1', $summary['metrics'][0]['value']);
        $this->assertSame('1', $summary['metrics'][2]['value']);
        $this->assertSame('/pricing', $summary['pages'][0]['label']);
        $this->assertSame('newsletter', $summary['sources'][0]['label']);
        $this->assertSame('launch', $summary['campaigns'][0]['label']);
        $this->assertSame('/pricing', $summary['entryPages'][0]['label']);
        $this->assertSame('/pricing', $summary['exitPages'][0]['label']);
    }

    public function test_goal_conversions_keep_the_matching_goal_version_and_visit(): void
    {
        [$user, $site] = $this->site();
        $goal = $site->goals()->create([
            'name' => 'Signup',
            'kind' => 'path',
            'match_type' => 'exact',
            'match_value' => '/thank-you',
            'active' => true,
        ]);
        $goal->versions()->update(['effective_from' => now()->subMinutes(10)]);
        $event = AnalyticsEvent::create([
            'site_id' => $site->id,
            'event_id' => (string) Str::uuid(),
            'type' => 'pageview',
            'occurred_at' => now()->subMinutes(5),
            'received_at' => now(),
            'path' => '/thank-you',
            'visitor_hash' => 'visitor-signup',
        ]);

        app(RebuildSiteVisits::class)->handle($site);
        app(RebuildGoalConversions::class)->handle($site);

        $conversion = GoalConversion::query()->sole();
        $this->assertSame($goal->id, $conversion->goal_id);
        $this->assertSame($goal->versions()->sole()->id, $conversion->goal_version_id);
        $this->assertSame($event->id, $conversion->analytics_event_id);
        $this->assertNotNull($conversion->visit_id);

        $goal->update(['match_value' => '/complete']);
        app(RebuildSiteVisits::class)->handle($site);
        app(RebuildGoalConversions::class)->handle($site);

        $this->assertDatabaseHas('goal_conversions', [
            'goal_id' => $goal->id,
            'analytics_event_id' => $event->id,
            'goal_version_id' => $goal->versions()->orderBy('id')->first()->id,
        ]);
    }

    public function test_admin_can_generate_and_download_a_scoped_csv_export(): void
    {
        Storage::fake('analytics-local');
        [$user, $site] = $this->site();
        $csrf = 'test-token';
        AnalyticsEvent::create([
            'site_id' => $site->id,
            'event_id' => (string) Str::uuid(),
            'type' => 'pageview',
            'occurred_at' => now(),
            'received_at' => now(),
            'path' => '/pricing',
            'visitor_hash' => 'visitor-1',
        ]);

        $this->withSession(['_token' => $csrf])->actingAs($user)->post(route('analytics.reports.exports.store', $site), ['_token' => $csrf, 'days' => 30])->assertRedirect();
        $export = ReportExport::query()->sole();
        $token = 'export-token';
        $export->update(['token_hash' => hash('sha256', $token)]);
        app(GenerateReportExport::class, ['exportId' => $export->id])->handle(app(OverviewReport::class));
        $export->refresh();

        $this->assertSame('completed', $export->status);
        Storage::disk('analytics-local')->assertExists($export->file_path);
        $this->assertStringContainsString('metrics,Pageviews,1', Storage::disk('analytics-local')->get($export->file_path));
        $this->actingAs($user)->get(route('analytics.reports.exports.download', $token))->assertOk()->assertHeader('content-disposition');
    }

    public function test_export_is_failed_when_the_private_storage_disk_rejects_the_csv(): void
    {
        [$user, $site] = $this->site();
        $export = ReportExport::create([
            'workspace_id' => $site->workspace_id,
            'site_id' => $site->id,
            'requested_by' => $user->id,
            'token_hash' => hash('sha256', 'storage-failure-token'),
            'filters' => ['days' => 30],
            'expires_at' => now()->addHour(),
        ]);
        $disk = \Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->once()->with('analytics-local')->andReturn($disk);

        try {
            app(GenerateReportExport::class, ['exportId' => $export->id])->handle(app(OverviewReport::class));
            $this->fail('A failed private-file write must not complete the report export.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The report export file could not be saved.', $exception->getMessage());
        }

        $this->assertSame('failed', $export->fresh()->status);
        $this->assertNull($export->fresh()->file_path);
    }

    /** @return array{0: User, 1: Site} */
    private function site(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Analytics workspace']);
        $workspace->users()->attach($user, ['role' => WorkspaceRole::Owner->value]);

        return [$user, $workspace->sites()->create([
            'name' => 'Example site',
            'domains' => ['example.com'],
            'timezone' => 'UTC',
            'verified_at' => now(),
        ])];
    }
}
