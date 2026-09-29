<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Queries\Analytics\AnalyticsReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportQueryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an analytics site in the given timezone.
     *
     * @param  string  $timezone
     * @return AnalyticsSite
     */
    private function site(string $timezone = 'UTC'): AnalyticsSite
    {
        $project = Project::factory()->for(Account::factory())->withServices(['analytics'])->create();

        return AnalyticsSite::factory()->for($project)->create(['timezone' => $timezone]);
    }

    /**
     * Record one event.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $at
     * @param  array<string, mixed>  $attributes
     * @return void
     */
    private function event(AnalyticsSite $site, CarbonImmutable $at, array $attributes = []): void
    {
        AnalyticsEvent::create([
            'site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => $at, 'received_at' => $at,
            'path' => '/', 'visitor_hash' => 'visitor-a', 'device_category' => 'Desktop', 'browser' => 'Firefox', ...$attributes,
        ]);
    }

    /**
     * Check the headline numbers, rankings, filters and comparison with the period before.
     *
     * @return void
     */
    public function test_the_report_counts_and_ranks_in_the_database(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00', 'UTC'));
        $site = $this->site();
        $now = CarbonImmutable::now();
        $this->event($site, $now->subHours(3), ['path' => '/pricing', 'visitor_hash' => 'a', 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'launch']);
        $this->event($site, $now->subHours(3)->addMinute(), ['path' => '/signup', 'visitor_hash' => 'a']);
        $this->event($site, $now->subHours(2), ['path' => '/pricing', 'visitor_hash' => 'b', 'referrer_host' => 'news.example', 'device_category' => 'Mobile']);
        $this->event($site, $now->subDays(1), ['path' => '/', 'visitor_hash' => 'c']);
        $this->event($site, $now->subDays(10), ['path' => '/', 'visitor_hash' => 'old']);
        $goal = $site->goals()->create(['name' => 'Signed up', 'kind' => 'path', 'match_type' => 'exact', 'match_value' => '/signup', 'active' => true]);
        $goal->versions()->create(['kind' => 'path', 'match_type' => 'exact', 'match_value' => '/signup', 'effective_from' => now()]);
        $this->travel(1)->minutes();
        $this->event($site, $now->addMinutes(2), ['path' => '/signup', 'visitor_hash' => 'd']);
        app(RebuildSiteReports::class)->handle($site);

        $report = app(AnalyticsReportQuery::class)->handle($site, 7);
        $metrics = array_column($report['metrics'], 'value', 'label');
        $this->assertSame(['5', '1', '4', '+400.0%'], [$metrics['Pageviews'], $metrics['Visitors'], $metrics['Visits'], $report['metrics'][0]['change']]);
        $this->assertSame(['label' => '/pricing', 'value' => 2], $report['pages'][0]);
        $this->assertContains(['label' => 'newsletter / email', 'value' => 1], $report['sources']);
        $this->assertContains(['label' => 'news.example', 'value' => 1], $report['sources']);
        $this->assertSame([['label' => 'launch', 'value' => 1]], $report['campaigns']);
        $this->assertSame([['name' => 'Signed up', 'value' => 1, 'kind' => 'path']], $report['goals'], 'Only completions after the goal was made count.');
        $this->assertSame(5, array_sum(array_column($report['series'], 'value')));
        $this->assertCount(7, $report['series']);

        $mobile = app(AnalyticsReportQuery::class)->handle($site, 7, ['device' => 'Mobile']);
        $this->assertSame(['1', '1'], [$mobile['metrics'][0]['value'], $mobile['metrics'][2]['value']]);
        $signups = app(AnalyticsReportQuery::class)->handle($site, 7, ['path' => '/signup']);
        $this->assertSame('2', $signups['metrics'][0]['value']);
        $this->assertSame('2', $signups['metrics'][2]['value'], 'Visits that included the page.');

        $fortnight = app(AnalyticsReportQuery::class)->handle($site, 14);
        $this->assertSame(6, array_sum(array_column($fortnight['series'], 'value')));
    }

    /**
     * Check that the daily chart follows the site's local days, including zones offset by half an hour.
     *
     * @return void
     */
    public function test_the_chart_follows_the_sites_local_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00', 'UTC'));
        $site = $this->site('Asia/Kolkata');
        // 18:45 UTC on the 18th is 00:15 on the 19th in India; 18:15 UTC is still 23:45 on the 18th.
        $this->event($site, CarbonImmutable::parse('2026-09-18 18:45', 'UTC'), ['visitor_hash' => 'late']);
        $this->event($site, CarbonImmutable::parse('2026-09-18 18:15', 'UTC'), ['visitor_hash' => 'early']);

        $series = array_column(app(AnalyticsReportQuery::class)->handle($site, 7)['series'], 'value', 'date');
        $this->assertSame([1, 1], [$series['Sep 18'], $series['Sep 19']]);
    }
}
