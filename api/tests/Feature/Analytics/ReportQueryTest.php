<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Data\Analytics\ReportPeriod;
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
        $this->assertSame('15s', $metrics['Visit duration'], 'One visit lasted a minute; the other three were single pages.');
        $this->assertContains(['label' => 'newsletter / email', 'value' => 1], $report['sources']);
        $this->assertContains(['label' => 'news.example', 'value' => 1], $report['sources']);
        $this->assertSame([['label' => 'launch', 'value' => 1]], $report['campaigns']);
        $this->assertSame([['name' => 'Signed up', 'value' => 1, 'kind' => 'path', 'revenue' => null]], $report['goals'], 'Only completions after the goal was made count.');
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

    /**
     * Check that today is shown per hour and compared with yesterday up to the same time.
     *
     * @return void
     */
    public function test_today_is_hourly_and_compared_with_yesterday_so_far(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:30', 'UTC'));
        $site = $this->site();
        $this->event($site, CarbonImmutable::parse('2026-09-20 09:10', 'UTC'), ['visitor_hash' => 'x']);
        $this->event($site, CarbonImmutable::parse('2026-09-20 11:50', 'UTC'), ['visitor_hash' => 'y']);
        $this->event($site, CarbonImmutable::parse('2026-09-19 10:00', 'UTC'), ['visitor_hash' => 'z']);
        $this->event($site, CarbonImmutable::parse('2026-09-19 20:00', 'UTC'), ['visitor_hash' => 'later']);

        $report = app(AnalyticsReportQuery::class)->handle($site, 1);
        $this->assertSame('hour', $report['granularity']);
        $this->assertCount(24, $report['series']);
        $series = array_column($report['series'], 'value', 'date');
        $this->assertSame([1, 1, 0], [$series['09:00'], $series['11:00'], $series['12:00']]);
        $this->assertSame(['2', '+100.0%'], [$report['metrics'][0]['value'], $report['metrics'][0]['change']], 'Yesterday after 12:30 is left out.');
    }

    /**
     * Check custom date ranges, comparing with the same dates a year earlier or not at all, and the browser filter.
     *
     * @return void
     */
    public function test_custom_ranges_compare_with_last_year_or_nothing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00', 'UTC'));
        $site = $this->site();
        $this->event($site, CarbonImmutable::parse('2026-09-02 10:00', 'UTC'), ['visitor_hash' => 'a']);
        $this->event($site, CarbonImmutable::parse('2026-09-03 10:00', 'UTC'), ['visitor_hash' => 'b', 'browser' => 'Safari']);
        $this->event($site, CarbonImmutable::parse('2026-09-09 10:00', 'UTC'), ['visitor_hash' => 'outside']);
        $this->event($site, CarbonImmutable::parse('2025-09-02 10:00', 'UTC'), ['visitor_hash' => 'last-year']);

        $period = ReportPeriod::between('UTC', '2026-09-05', '2026-09-01', 'year');
        $this->assertNotNull($period);
        $this->assertSame(['2026-09-01', '2026-09-05', 5], [$period->start->toDateString(), $period->end->toDateString(), $period->days], 'Dates in the wrong order are swapped.');
        $report = app(AnalyticsReportQuery::class)->handle($site, $period);
        $this->assertSame(['2', '+100.0%'], [$report['metrics'][0]['value'], $report['metrics'][0]['change']]);
        $this->assertCount(5, $report['series']);
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-05', 'compare' => 'year'], $period->query());

        $none = app(AnalyticsReportQuery::class)->handle($site, ReportPeriod::between('UTC', '2026-09-01', '2026-09-05', 'none') ?? 30);
        $this->assertNull($none['metrics'][0]['change']);
        $this->assertSame('1', app(AnalyticsReportQuery::class)->handle($site, $period, ['browser' => 'Safari'])['metrics'][0]['value']);
        $this->assertNull(ReportPeriod::between('UTC', 'yesterday', '2026-09-05'));
        $this->assertSame('2026-09-20', ReportPeriod::between('UTC', '2026-09-19', '2030-01-01')?->end->toDateString(), 'The end stops at today.');
    }
}
