<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Models\Account;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsNotification;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\SavedView;
use App\Models\User;
use App\Notifications\AnalyticsSiteReportNotification;
use App\Services\Analytics\SiteNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SiteNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that reports and spike alerts are added and removed from the site's settings, sent once per period by
     * email and Slack, and that bad targets are refused.
     *
     * @return void
     */
    public function test_reports_and_spike_alerts_are_sent_once(): void
    {
        Notification::fake();
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $this->travelTo(CarbonImmutable::parse('2026-09-21 07:30', 'UTC'));
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['name' => 'Shop', 'timezone' => 'UTC']);
        $base = "/projects/{$project->id}/analytics/sites/{$site->id}";

        $this->actingAs($owner)->post("{$base}/notifications", ['kind' => 'weekly', 'channel' => 'email', 'target' => 'team@example.com'])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/notifications", ['kind' => 'monthly', 'channel' => 'slack', 'target' => 'https://hooks.slack.com/services/T1/B2/abc'])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/notifications", ['kind' => 'spike', 'channel' => 'email', 'target' => 'team@example.com', 'threshold' => 2])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/notifications", ['kind' => 'weekly', 'channel' => 'slack', 'target' => 'https://evil.example/hook'])->assertSessionHasErrors('target');
        $this->actingAs($owner)->post("{$base}/notifications", ['kind' => 'spike', 'channel' => 'email', 'target' => 'team@example.com'])->assertSessionHasErrors('threshold');
        $this->assertSame(3, AnalyticsNotification::query()->count());
        $this->actingAs($owner)->get($base)->assertOk()->assertSee(__('Reports and alerts'))->assertSee('team@example.com')->assertDontSee('hooks.slack.com/services/T1');

        AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subDays(3), 'received_at' => now()->subDays(3), 'path' => '/pricing', 'visitor_hash' => 'a']);
        app(RebuildSiteReports::class)->handle($site);
        $notifier = app(SiteNotifier::class);
        $this->assertSame(0, $notifier->sendDueReports(), 'Not before 8am.');
        $this->travelTo(CarbonImmutable::parse('2026-09-21 08:05', 'UTC'));
        $this->assertSame(1, $notifier->sendDueReports(), 'Monday brings the weekly report; the monthly one waits for the 1st.');
        $this->assertSame(0, $notifier->sendDueReports(), 'Once per week.');
        Notification::assertSentTo(new AnonymousNotifiable, AnalyticsSiteReportNotification::class, function (AnalyticsSiteReportNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
            return $notifiable->routes['mail'] === 'team@example.com' && str_contains($notification->subject, 'Shop last week') && str_contains(implode(' ', $notification->lines), '/pricing');
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', 'UTC'));
        $this->assertSame(1, $notifier->sendDueReports());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.slack.com/services/T1/B2/abc' && str_contains((string) $request['text'], 'Shop in September 2026'));

        foreach (['x', 'y'] as $visitor) {
            AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subMinute(), 'received_at' => now()->subMinute(), 'path' => '/launch', 'visitor_hash' => $visitor]);
        }
        $this->assertSame(1, $notifier->checkSpikes());
        $this->assertSame(0, $notifier->checkSpikes(), 'Not again within three hours.');

        $spike = AnalyticsNotification::query()->where('kind', 'spike')->sole();
        $this->actingAs($owner)->delete("{$base}/notifications/{$spike->id}")->assertRedirect();
        $this->assertSame(2, AnalyticsNotification::query()->count());
    }

    /**
     * Check a weekly CSV export uses a saved view's filters and arrives as an attachment, CSV exports can't go to
     * Slack or use someone else's view, and unusual traffic alerts fire on a drop against the same weekday but stay
     * quiet on a normal day.
     *
     * @return void
     */
    public function test_csv_exports_and_unusual_traffic_alerts(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-21 09:00', 'UTC'));
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['name' => 'Shop', 'timezone' => 'UTC']);
        $base = "/projects/{$project->id}/analytics/sites/{$site->id}";
        $this->actingAs($owner)->post('/saved-views', ['saved_view_page' => 'analytics.overview', 'saved_view_name' => 'Blog', 'parameters' => ['project' => $project->id], 'query' => ['site' => (string) $site->id, 'path' => '/blog', 'days' => '7']])->assertRedirect();
        $view = SavedView::query()->sole();
        $theirs = new SavedView;
        $theirs->forceFill(['user_id' => User::factory()->create()->id, 'page' => 'analytics.overview', 'name' => 'Theirs', 'parameters' => ['project' => $project->id], 'query' => []])->save();

        $this->actingAs($owner)->get($base)->assertOk()->assertSee('<option value="'.$view->id.'"', false)->assertDontSee('Theirs');
        $this->actingAs($owner)->post("{$base}/notifications", ['kind' => 'weekly_csv', 'channel' => 'slack', 'target' => 'https://hooks.slack.com/services/T1/B2/abc'])->assertSessionHasErrors('channel');
        $this->actingAs($owner)->post("{$base}/notifications", ['kind' => 'weekly_csv', 'channel' => 'email', 'target' => 'team@example.com', 'saved_view_id' => $theirs->id])->assertSessionHasErrors('saved_view_id');
        $this->actingAs($owner)->post("{$base}/notifications", ['kind' => 'weekly_csv', 'channel' => 'email', 'target' => 'team@example.com', 'saved_view_id' => $view->id])->assertRedirect();
        $this->actingAs($owner)->post("{$base}/notifications", ['kind' => 'anomaly', 'channel' => 'email', 'target' => 'alerts@example.com'])->assertRedirect();
        $export = AnalyticsNotification::query()->where('kind', 'weekly_csv')->sole();
        $this->assertSame(['path' => '/blog'], $export->filters);

        foreach (['/blog', '/blog', '/pricing'] as $path) {
            AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subDays(3), 'received_at' => now(), 'path' => $path, 'visitor_hash' => 'a']);
        }
        app(RebuildSiteReports::class)->handle($site);
        $this->assertSame(1, app(SiteNotifier::class)->sendDueReports());
        Notification::assertSentTo(new AnonymousNotifiable, AnalyticsSiteReportNotification::class, function (AnalyticsSiteReportNotification $notification): bool {
            return str_contains($notification->subject, 'Blog') && $notification->attachment !== null
                && str_contains($notification->attachment['csv'], 'metrics,Pageviews,2') && ! str_contains($notification->attachment['csv'], "\npages,/pricing")
                && str_ends_with($notification->attachment['name'], '.csv');
        });

        // Eight Sundays of about 100 visitors, then 30 yesterday.
        $sunday = CarbonImmutable::parse('2026-09-20', 'UTC');
        foreach (range(1, 8) as $weeks) {
            AnalyticsDailyAggregate::query()->create(['site_id' => $site->id, 'local_date' => $sunday->subWeeks($weeks)->toDateString(), 'dimension' => 'all', 'dimension_value' => null, 'visitors' => 95 + $weeks, 'conversions' => 0]);
        }
        AnalyticsDailyAggregate::query()->create(['site_id' => $site->id, 'local_date' => $sunday->toDateString(), 'dimension' => 'all', 'dimension_value' => null, 'visitors' => 30, 'conversions' => 0]);
        $notifier = app(SiteNotifier::class);
        $this->assertSame(1, $notifier->checkAnomalies());
        $this->assertSame(0, $notifier->checkAnomalies(), 'Once a day.');
        Notification::assertSentTo(new AnonymousNotifiable, AnalyticsSiteReportNotification::class, fn (AnalyticsSiteReportNotification $notification): bool => str_contains($notification->subject, 'Unusual traffic') && str_contains($notification->lines[0], 'Visitors were 70% below normal: 30, against about 100 on a typical Sunday'));

        $this->travelTo(CarbonImmutable::parse('2026-09-22 09:00', 'UTC'));
        AnalyticsDailyAggregate::query()->create(['site_id' => $site->id, 'local_date' => '2026-09-21', 'dimension' => 'all', 'dimension_value' => null, 'visitors' => 10, 'conversions' => 0]);
        $this->assertSame(0, $notifier->checkAnomalies(), 'No history for Mondays, so nothing to judge.');
        $this->assertSame('2026-09-21', AnalyticsNotification::query()->where('kind', 'anomaly')->sole()->last_period);
    }
}
