<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Models\Account;
use App\Models\AnalyticsAdSpend;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\CampaignResultsQuery;
use App\Support\CampaignLink;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CampaignsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that the builder tags links (keeping other parameters and replacing old UTM ones) and that campaign
     * results rank tagged visits with their conversions.
     *
     * @return void
     */
    public function test_campaign_links_are_built_and_campaigns_ranked(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['name' => 'Shop', 'domains' => ['shop.example.com']]);
        $visit = function (string $campaign, int $conversions) use ($site): void {
            (new AnalyticsVisit)->forceFill(['site_id' => $site->id, 'visit_key' => uniqid('v', true), 'started_at' => now()->subDay(), 'last_seen_at' => now()->subDay(),
                'entry_utm_source' => 'newsletter', 'entry_utm_medium' => 'email', 'entry_utm_campaign' => $campaign, 'pageviews' => 3, 'conversion_count' => $conversions])->save();
        };
        $visit('autumn', 1);
        $visit('autumn', 0);
        $visit('spring', 0);

        $base = "/api/app/projects/{$project->id}/analytics/campaigns";
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('builder.url', 'https://shop.example.com/')
            ->assertJsonPath('results.0.campaign', 'autumn')->assertJsonPath('results.0.source', 'newsletter')->assertJsonPath('results.0.medium', 'email')
            ->assertJsonPath('results.0.visits', 2)->assertJsonPath('results.0.pageviews', 6)->assertJsonPath('results.0.converted', 1)
            ->assertJsonPath('results.0.rate', fn ($rate): bool => (float) $rate === 50.0)->assertJsonPath('results.1.campaign', 'spring');
        $this->actingAs($owner)->getJson($base.'?'.http_build_query(['url' => 'https://shop.example.com/sale?ref=x&utm_source=old#top', 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'autumn launch']))
            ->assertOk()->assertJsonPath('builder.link', 'https://shop.example.com/sale?ref=x&utm_source=newsletter&utm_medium=email&utm_campaign=autumn%20launch#top');
        $this->actingAs($owner)->getJson($base.'?url=javascript:alert(1)')->assertOk()->assertJsonPath('builder.invalid', true)->assertJsonPath('builder.link', null);

        $this->assertNull(CampaignLink::build('ftp://x.test/', []));
        $this->assertSame('https://x.test/', CampaignLink::build('https://x.test', ['utm_source' => ' ']));
    }

    /**
     * Check ad spend imports from Google Ads and Meta style exports (decimal commas, several rows a day, a source
     * column), matches campaigns ignoring case, and gives cost, cost per conversion and return on ad spend; bad files
     * are refused, re-importing replaces a day, and spend can be removed.
     *
     * @return void
     */
    public function test_ad_spend_gives_cost_per_conversion_and_roas(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00', 'UTC'));
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['timezone' => 'UTC']);
        $goal = $site->goals()->create(['name' => 'Bought', 'kind' => 'event', 'match_type' => 'exact', 'match_value' => 'purchase', 'active' => true]);
        $goal->versions()->create(['kind' => 'event', 'match_type' => 'exact', 'match_value' => 'purchase', 'effective_from' => now()->subYear()]);
        foreach (['a' => 60, 'b' => 90, 'c' => null] as $session => $revenue) {
            $event = ['site_id' => $site->id, 'occurred_at' => now()->subDay(), 'received_at' => now(), 'path' => '/', 'session_id' => $session, 'visitor_hash' => $session, 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'Autumn'];
            AnalyticsEvent::create([...$event, 'event_id' => (string) Str::uuid(), 'type' => 'pageview']);
            if ($revenue !== null) {
                AnalyticsEvent::create([...$event, 'event_id' => (string) Str::uuid(), 'type' => 'event', 'occurred_at' => now()->subDay()->addMinute(), 'properties' => ['name' => 'purchase', 'revenue' => $revenue, 'currency' => 'EUR']]);
            }
        }
        app(RebuildSiteReports::class)->handle($site);
        $url = "/api/app/projects/{$project->id}/analytics/sites/{$site->id}/ad-spend";
        $upload = fn (string $csv, string $source = 'google', string $currency = 'EUR') => $this->actingAs($owner)->postJson($url, ['file' => UploadedFile::fake()->createWithContent('spend.csv', $csv), 'source' => $source, 'currency' => $currency]);

        $upload("Name,Spend\nx,1")->assertJsonValidationErrors('file');
        $upload("Day,Campaign,Cost\n2026-09-22,Autumn,oops")->assertJsonValidationErrors('file');
        // Google Ads: one row per ad group, decimal commas and semicolons.
        $upload("\u{FEFF}Day;Campaign;Cost;Clicks;Impr.\n2026-09-22;autumn;\"30,00\";10;1000\n2026-09-22;autumn;20,00;5;500\n2026-09-21;autumn;10,00;2;100\n")->assertSuccessful()->assertJsonPath('message', 'Imported 2 days of spend.');
        // Meta, with its own source column: a campaign with spend but no visits.
        $upload("Reporting starts,Campaign name,Amount spent (EUR),Source\n2026-09-22,Retargeting,\"1,234.50\",facebook\n")->assertSuccessful();
        $day = AnalyticsAdSpend::query()->where('campaign', 'autumn')->whereDate('date', '2026-09-22')->sole();
        $this->assertSame([5000, 15, 1500, 'EUR', 'google'], [$day->cost_cents, $day->clicks, $day->impressions, $day->currency, $day->source]);

        $rows = app(CampaignResultsQuery::class)->handle($site);
        $this->assertSame(['Autumn', 3, 2, '€60.00', '€30.00', 2.5], [$rows[0]['campaign'], $rows[0]['visits'], $rows[0]['converted'], $rows[0]['cost'], $rows[0]['cost_per_conversion'], $rows[0]['roas']], 'Revenue €150 on €60 of spend.');
        $this->assertSame(['Retargeting', 0, '€1,234.50', null], [$rows[1]['campaign'], $rows[1]['visits'], $rows[1]['cost'], $rows[1]['roas']]);

        $upload("Day,Campaign,Cost\n2026-09-22,autumn,75\n")->assertSuccessful();
        $this->assertSame(7500, AnalyticsAdSpend::query()->where('campaign', 'autumn')->whereDate('date', '2026-09-22')->sole()->cost_cents, 'Re-importing a day replaces it.');
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/analytics/campaigns")->assertOk()->assertJsonHasText('€85.00')->assertJsonHasText('facebook');
        $this->actingAs($owner)->deleteJson($url, ['source' => 'facebook'])->assertSuccessful();
        $this->assertSame(['google'], AnalyticsAdSpend::query()->distinct()->pluck('source')->all());
    }
}
