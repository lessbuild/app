<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsNotification;
use App\Models\AnalyticsSite;
use App\Models\Project;
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
}
