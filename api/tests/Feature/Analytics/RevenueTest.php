<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Queries\Analytics\CampaignResultsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RevenueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that revenue on custom events is kept (and cleaned), totalled per goal and currency, and credited to the
     * campaign whose visit it happened in.
     *
     * @return void
     */
    public function test_revenue_is_totalled_per_goal_and_campaign(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['domains' => ['example.com'], 'timezone' => 'UTC', 'verified_at' => now()]);
        $goal = $site->goals()->create(['name' => 'Purchase', 'kind' => 'event', 'match_type' => 'exact', 'match_value' => 'purchase', 'active' => true]);
        $goal->versions()->create(['kind' => 'event', 'match_type' => 'exact', 'match_value' => 'purchase', 'effective_from' => now()->subDay()]);

        $this->withHeader('Origin', 'https://example.com')->postJson("/api/v1/collect/{$site->public_id}", ['events' => [
            ['id' => (string) Str::uuid(), 'type' => 'event', 'path' => '/', 'properties' => ['name' => 'purchase', 'revenue' => '19.999', 'currency' => 'eur']],
            ['id' => (string) Str::uuid(), 'type' => 'event', 'path' => '/', 'properties' => ['name' => 'purchase', 'revenue' => -4]],
            ['id' => (string) Str::uuid(), 'type' => 'event', 'path' => '/', 'properties' => ['name' => 'purchase', 'revenue' => 5, 'currency' => 'dollars']],
        ]])->assertAccepted();
        $this->assertSame([
            ['name' => 'purchase', 'revenue' => 20, 'currency' => 'EUR'],
            ['name' => 'purchase'],
            ['name' => 'purchase', 'revenue' => 5, 'currency' => 'USD'],
        ], AnalyticsEvent::query()->orderBy('id')->pluck('properties')->all());
        AnalyticsEvent::query()->delete();

        $at = now()->subHour();
        $event = fn (string $type, string $visitor, array $attributes = []) => AnalyticsEvent::create([
            'site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => $type, 'occurred_at' => $at, 'received_at' => $at, 'path' => '/', 'visitor_hash' => $visitor, ...$attributes,
        ]);
        $event('pageview', 'a', ['utm_campaign' => 'spring', 'utm_source' => 'newsletter']);
        $event('event', 'a', ['properties' => ['name' => 'purchase', 'revenue' => 40, 'currency' => 'EUR']]);
        $event('pageview', 'b', ['utm_campaign' => 'spring', 'utm_source' => 'newsletter']);
        $event('event', 'b', ['properties' => ['name' => 'purchase', 'revenue' => 9.5, 'currency' => 'EUR']]);
        $event('event', 'c', ['properties' => ['name' => 'purchase', 'revenue' => 12, 'currency' => 'USD']]);
        app(RebuildSiteReports::class)->handle($site);

        $report = app(AnalyticsReportQuery::class)->handle($site, 7);
        $this->assertSame(['name' => 'Purchase', 'value' => 3, 'kind' => 'event', 'revenue' => '€49.50 · $12.00'], $report['goals'][0]);
        $campaign = app(CampaignResultsQuery::class)->handle($site)[0];
        $this->assertSame(['spring', '€49.50'], [$campaign['campaign'], $campaign['revenue']]);

        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/analytics?days=7")->assertOk()->assertJsonHasText('€49.50 · $12.00');
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/analytics/campaigns")->assertOk()->assertJsonHasText('€49.50');
    }
}
