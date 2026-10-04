<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsExport;
use App\Models\AnalyticsGoal;
use App\Models\AnalyticsSite;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private AnalyticsSite $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for(Account::factory()->withMember($this->owner))->withServices(['analytics'])->create();
        $this->site = AnalyticsSite::factory()->for($this->project)->create(['name' => 'Shop']);
        foreach (['/pricing', '/pricing', '/thank-you'] as $path) {
            AnalyticsEvent::create(['site_id' => $this->site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subHour(), 'received_at' => now(), 'path' => $path, 'visitor_hash' => 'visitor-1', 'utm_source' => 'newsletter', 'device_category' => 'Desktop']);
        }
        app(RebuildSiteReports::class)->handle($this->site);
    }

    public function test_the_report_shows_metrics_the_chart_and_honours_filters(): void
    {
        $base = "/api/app/projects/{$this->project->id}/analytics";

        $this->actingAs($this->owner)->getJson($base)
            ->assertOk()
            ->assertJsonPath('report.granularity', 'day')
            ->assertJsonPath('report.hasData', true)
            ->assertJsonHasText('/pricing')
            ->assertJsonHasText('newsletter')
            ->assertJsonLacksText(__('Outbound links'));

        $this->actingAs($this->owner)->getJson("{$base}?days=7&path=/thank-you")->assertOk()->assertJsonHasText('/thank-you')->assertJsonPath('filters.path', '/thank-you');
        $this->actingAs($this->owner)->getJson("{$base}?days=9999&site=999")->assertOk()->assertJsonHasText('Shop');

        $from = now()->subDays(3)->toDateString();
        $to = now()->toDateString();
        $this->actingAs($this->owner)->getJson("{$base}?from={$from}&to={$to}&compare=year&browser=Nope")->assertOk()
            ->assertJsonPath('report.period.start', $from)->assertJsonPath('report.period.custom', true)->assertJsonPath('report.period.compare', 'year')
            ->assertJsonPath('report.hasData', false);
    }

    public function test_today_shows_pageviews_per_hour_and_the_live_panel_refreshes_on_its_own(): void
    {
        // Midday, so "a minute ago" is still today whenever the suite runs.
        $this->travelTo(now()->setTime(12, 0));
        $base = "/api/app/projects/{$this->project->id}/analytics";

        $this->actingAs($this->owner)->getJson("{$base}?days=1")->assertOk()
            ->assertJsonPath('report.granularity', 'hour')->assertJsonHasText('00:00')->assertJsonHasText('23:00')
            ->assertJsonPath('report.recent.visitorCount', 0);

        AnalyticsEvent::create(['site_id' => $this->site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subMinute(), 'received_at' => now(), 'path' => '/checkout', 'visitor_hash' => 'visitor-live']);
        $live = $this->actingAs($this->owner)->getJson("{$base}?days=1&live=1")->assertOk();
        $live->assertJsonPath('recent.visitorCount', 1)->assertJsonPath('recent.events.0.path', '/checkout')->assertJsonMissingPath('report');
    }

    public function test_the_report_lists_releases_that_went_live_in_the_period(): void
    {
        $production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $release = fn (string $version): int => Release::factory()->create(['project_id' => $this->project->id, 'version' => $version])->id;
        Deployment::factory()->create(['environment_id' => $production->id, 'release_id' => $release('v2.4.0'), 'source' => 'deploy', 'deployed_at' => now()->subDays(2)]);
        Deployment::factory()->create(['environment_id' => $production->id, 'release_id' => $release('v1.0.0'), 'deployed_at' => now()->subDays(60)]);

        $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/analytics")
            ->assertOk()->assertJsonCount(1, 'releases')->assertJsonPath('releases.0.version', 'v2.4.0')->assertJsonPath('releases.0.fromDeploy', true)->assertJsonLacksText('v1.0.0');
    }

    public function test_goals_are_created_edited_and_removed_and_conversions_are_recounted(): void
    {
        $base = "/api/app/projects/{$this->project->id}/analytics/sites/{$this->site->id}/goals";

        $this->actingAs($this->owner)->postJson($base, ['name' => 'Bought', 'kind' => 'path', 'match_type' => 'exact', 'match_value' => '/thank-you', 'active' => '1'])->assertSuccessful();
        $goal = AnalyticsGoal::query()->sole();
        $this->assertSame(0, $goal->conversions()->count(), 'A new goal counts from its creation, not before.');
        $this->travel(1)->minutes();
        AnalyticsEvent::create(['site_id' => $this->site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now(), 'received_at' => now(), 'path' => '/thank-you', 'visitor_hash' => 'visitor-2']);
        app(RebuildSiteReports::class)->handle($this->site);
        $this->assertSame(1, $goal->conversions()->count());
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$this->project->id}/analytics/goals")->assertOk()->assertJsonHasText('Bought')->assertJsonHasText('/thank-you');

        $this->travel(1)->minutes();
        $this->actingAs($this->owner)->putJson("{$base}/{$goal->id}", ['name' => 'Priced', 'kind' => 'path', 'match_type' => 'exact', 'match_value' => '/pricing', 'active' => '1'])->assertSuccessful();
        $this->assertSame(2, $goal->versions()->count());
        $this->assertSame(1, $goal->refresh()->conversions()->count(), 'Earlier conversions keep the version they were counted under.');

        $this->actingAs($this->owner)->deleteJson("{$base}/{$goal->id}")->assertSuccessful();
        $this->assertSame(0, AnalyticsGoal::query()->count());
    }

    public function test_viewers_read_reports_but_do_not_manage_goals(): void
    {
        $viewer = User::factory()->create();
        $this->project->account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);

        $this->actingAs($viewer)->getJson("/api/app/projects/{$this->project->id}/analytics")->assertOk();
        $this->actingAs($viewer)->getJson("/api/app/projects/{$this->project->id}/analytics/goals")->assertOk()->assertJsonPath('canManage', false);
        $this->actingAs($viewer)->postJson("/api/app/projects/{$this->project->id}/analytics/sites/{$this->site->id}/goals", ['name' => 'x', 'kind' => 'path', 'match_type' => 'exact', 'match_value' => '/'])->assertForbidden();
    }

    public function test_a_csv_export_is_queued_prepared_and_downloaded(): void
    {
        Storage::fake('local');

        $response = $this->actingAs($this->owner)->postJson("/api/app/projects/{$this->project->id}/analytics/sites/{$this->site->id}/exports", ['days' => 30]);
        $export = AnalyticsExport::query()->sole();
        $response->assertSuccessful();
        $this->assertSame('completed', $export->refresh()->status, 'The sync queue runs the export straight away in tests.');

        $page = '/api/app'.$response->json('redirect');
        $this->actingAs($this->owner)->getJson($page)->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('downloadUrl', $page.'/download');
        $download = $page.'/download';
        $this->actingAs($this->owner)->getJson($download)->assertOk()->assertHeader('content-disposition');

        $other = Project::factory()->for($this->project->account)->withServices(['analytics'])->create();
        $this->actingAs($this->owner)->get(str_replace($this->project->id, $other->id, $download))->assertNotFound();
    }
}
