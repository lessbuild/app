<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AnalyticsReportQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PageSpeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that Web Vitals are accepted with only sane numbers, summarised at the 75th percentile with ratings,
     * that the slowest pages are listed, and that they don't count as pageviews or devices.
     *
     * @return void
     */
    public function test_web_vitals_are_collected_and_summarised(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['domains' => ['example.com'], 'timezone' => 'UTC', 'verified_at' => now()]);

        $this->withHeader('Origin', 'https://example.com')->postJson("/api/v1/collect/{$site->public_id}", ['events' => [
            ['id' => (string) Str::uuid(), 'type' => 'vitals', 'path' => '/', 'properties' => ['lcp' => 1800, 'inp' => 90, 'cls' => 0.02, 'ttfb' => 300, 'secret' => 'x', 'fid' => 5]],
            ['id' => (string) Str::uuid(), 'type' => 'vitals', 'path' => '/', 'properties' => ['lcp' => -5, 'cls' => 500]],
        ]])->assertAccepted()->assertJsonPath('accepted', 2);
        $this->assertSame([['lcp' => 1800, 'inp' => 90, 'ttfb' => 300, 'cls' => 0.02], null], AnalyticsEvent::query()->orderBy('id')->pluck('properties')->all());
        AnalyticsEvent::query()->delete();

        $record = function (string $path, int $lcp, float $cls = 0.01) use ($site): void {
            AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'vitals', 'occurred_at' => now()->subHour(), 'received_at' => now(), 'path' => $path, 'device_category' => 'Desktop', 'properties' => ['lcp' => $lcp, 'inp' => 150, 'cls' => $cls, 'ttfb' => 400]]);
        };
        foreach ([1000, 1200, 1400, 5000, 6000] as $lcp) {
            $record('/pricing', $lcp);
        }
        foreach ([900, 1000, 1100, 1200, 1300] as $lcp) {
            $record('/', $lcp, 0.3);
        }
        AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subHour(), 'received_at' => now(), 'path' => '/', 'device_category' => 'Mobile', 'visitor_hash' => 'v']);

        $report = app(AnalyticsReportQuery::class)->handle($site, 7);
        $this->assertSame(10, $report['vitals']['samples']);
        $this->assertSame(['value' => 1400.0, 'rating' => 'good'], $report['vitals']['metrics']['lcp']);
        $this->assertSame(['value' => 0.3, 'rating' => 'poor'], $report['vitals']['metrics']['cls']);
        $this->assertSame(['value' => 150.0, 'rating' => 'good'], $report['vitals']['metrics']['inp']);
        $this->assertSame(['path' => '/pricing', 'lcp' => 5000.0, 'samples' => 5], $report['vitals']['slowPages'][0]);
        $this->assertSame('1', $report['metrics'][0]['value'], 'Vitals are not pageviews.');
        $this->assertSame([['label' => 'Mobile', 'value' => 1]], $report['devices']);

        $this->actingAs($owner)->get("/projects/{$project->id}/analytics?days=7")->assertOk()
            ->assertSee(__('Page speed'))->assertSee('1.40 s')->assertSee(__('Poor'))->assertSee(__('Slowest pages to load'));
    }
}
