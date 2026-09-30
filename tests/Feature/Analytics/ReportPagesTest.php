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
        $base = "/projects/{$this->project->id}/analytics";

        $this->actingAs($this->owner)->get($base)
            ->assertOk()
            ->assertSee(__('Pageviews per day'))
            ->assertSee('role="list" aria-label="'.__('Pageviews per day').'"', false)
            ->assertSee('/pricing')
            ->assertSee('newsletter')
            ->assertDontSee(__('Outbound links'));

        $this->actingAs($this->owner)->get("{$base}?days=7&path=/thank-you")->assertOk()->assertSee('/thank-you')->assertSee(__('Clear'));
        $this->actingAs($this->owner)->get("{$base}?days=9999&site=999")->assertOk()->assertSee('Shop');

        $from = now()->subDays(3)->toDateString();
        $to = now()->toDateString();
        $this->actingAs($this->owner)->get("{$base}?from={$from}&to={$to}&compare=year&browser=Nope")->assertOk()
            ->assertSee('value="'.$from.'"', false)->assertSee(__('Same period last year'))->assertSee(__('No pageviews in this period yet.'))
            ->assertSee('data-modal-trigger="save-view-analytics-overview"', false);
    }

    public function test_today_shows_pageviews_per_hour_and_the_live_panel_refreshes_on_its_own(): void
    {
        $base = "/projects/{$this->project->id}/analytics";

        $this->actingAs($this->owner)->get("{$base}?days=1")->assertOk()
            ->assertSee(__('Pageviews per hour'))->assertSee('00:00')->assertSee('23:00')
            ->assertSee('id="analytics-live"', false)->assertSee('data-live-region', false);

        AnalyticsEvent::create(['site_id' => $this->site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subMinute(), 'received_at' => now(), 'path' => '/checkout', 'visitor_hash' => 'visitor-live']);
        $live = $this->actingAs($this->owner)->withHeader('X-Live-Region', '1')->get("{$base}?days=1")->assertOk();
        $live->assertSee('id="analytics-live"', false)->assertSee('/checkout')->assertSee('1 visitor in the last 5 minutes')->assertDontSee(__('Pageviews per hour'));
    }

    public function test_the_report_lists_releases_that_went_live_in_the_period(): void
    {
        $production = $this->project->environments()->where('slug', 'production')->firstOrFail();
        $release = fn (string $version): int => Release::factory()->create(['project_id' => $this->project->id, 'version' => $version])->id;
        Deployment::factory()->create(['environment_id' => $production->id, 'release_id' => $release('v2.4.0'), 'source' => 'deploy', 'deployed_at' => now()->subDays(2)]);
        Deployment::factory()->create(['environment_id' => $production->id, 'release_id' => $release('v1.0.0'), 'deployed_at' => now()->subDays(60)]);

        $this->actingAs($this->owner)->get("/projects/{$this->project->id}/analytics")
            ->assertOk()->assertSee('Releases in this period')->assertSee('v2.4.0')->assertDontSee('v1.0.0')
            ->assertSee(now()->subDays(2)->format('M j').': ', false)->assertSee('Released v2.4.0');
    }

    public function test_goals_are_created_edited_and_removed_and_conversions_are_recounted(): void
    {
        $base = "/projects/{$this->project->id}/analytics/sites/{$this->site->id}/goals";

        $this->actingAs($this->owner)->post($base, ['name' => 'Bought', 'kind' => 'path', 'match_type' => 'exact', 'match_value' => '/thank-you', 'active' => '1'])->assertRedirect();
        $goal = AnalyticsGoal::query()->sole();
        $this->assertSame(0, $goal->conversions()->count(), 'A new goal counts from its creation, not before.');
        $this->travel(1)->minutes();
        AnalyticsEvent::create(['site_id' => $this->site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now(), 'received_at' => now(), 'path' => '/thank-you', 'visitor_hash' => 'visitor-2']);
        app(RebuildSiteReports::class)->handle($this->site);
        $this->assertSame(1, $goal->conversions()->count());
        $this->actingAs($this->owner)->get("/projects/{$this->project->id}/analytics/goals")->assertOk()->assertSee('Bought')->assertSee('/thank-you');

        $this->travel(1)->minutes();
        $this->actingAs($this->owner)->put("{$base}/{$goal->id}", ['name' => 'Priced', 'kind' => 'path', 'match_type' => 'exact', 'match_value' => '/pricing', 'active' => '1'])->assertRedirect();
        $this->assertSame(2, $goal->versions()->count());
        $this->assertSame(1, $goal->refresh()->conversions()->count(), 'Earlier conversions keep the version they were counted under.');

        $this->actingAs($this->owner)->delete("{$base}/{$goal->id}")->assertRedirect();
        $this->assertSame(0, AnalyticsGoal::query()->count());
    }

    public function test_viewers_read_reports_but_do_not_manage_goals(): void
    {
        $viewer = User::factory()->create();
        $this->project->account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);

        $this->actingAs($viewer)->get("/projects/{$this->project->id}/analytics")->assertOk();
        $this->actingAs($viewer)->get("/projects/{$this->project->id}/analytics/goals")->assertOk()->assertDontSee(__('Add goal'));
        $this->actingAs($viewer)->post("/projects/{$this->project->id}/analytics/sites/{$this->site->id}/goals", ['name' => 'x', 'kind' => 'path', 'match_type' => 'exact', 'match_value' => '/'])->assertForbidden();
    }

    public function test_a_csv_export_is_queued_prepared_and_downloaded(): void
    {
        Storage::fake('local');

        $response = $this->actingAs($this->owner)->post("/projects/{$this->project->id}/analytics/sites/{$this->site->id}/exports", ['days' => 30]);
        $export = AnalyticsExport::query()->sole();
        $response->assertRedirect();
        $this->assertSame('completed', $export->refresh()->status, 'The sync queue runs the export straight away in tests.');

        $this->actingAs($this->owner)->get((string) $response->headers->get('Location'))->assertOk()->assertSee(__('Download CSV'));
        $download = str_replace('/exports/', '/exports/', (string) $response->headers->get('Location')).'/download';
        $this->actingAs($this->owner)->get($download)->assertOk()->assertHeader('content-disposition');

        $other = Project::factory()->for($this->project->account)->withServices(['analytics'])->create();
        $this->actingAs($this->owner)->get(str_replace($this->project->id, $other->id, $download))->assertNotFound();
    }
}
