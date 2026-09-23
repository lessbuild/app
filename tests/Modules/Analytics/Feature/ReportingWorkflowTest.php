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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportingWorkflowTest extends TestCase
{
    use RefreshDatabase;

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
        Storage::fake('local');
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

        $this->withSession(['_token' => $csrf])->actingAs($user)->post(route('reports.exports.store', $site), ['_token' => $csrf, 'days' => 30])->assertRedirect();
        $export = ReportExport::query()->sole();
        $token = 'export-token';
        $export->update(['token_hash' => hash('sha256', $token)]);
        app(GenerateReportExport::class, ['exportId' => $export->id])->handle(app(OverviewReport::class));
        $export->refresh();

        $this->assertSame('completed', $export->status);
        $this->actingAs($user)->get(route('reports.exports.download', $token))->assertOk()->assertHeader('content-disposition');
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
