<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RefreshRecentAggregates;
use App\Contracts\Analytics\CountryLookup;
use App\Models\Account;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Services\Analytics\DbIpCountryLookup;
use App\Support\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CountriesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that the country is looked up when events arrive, carried onto visits and daily totals, ranked in the
     * report, filterable, and shown with its name.
     *
     * @return void
     */
    public function test_countries_are_recorded_ranked_and_filterable(): void
    {
        $this->app->instance(CountryLookup::class, new class implements CountryLookup
        {
            /**
             * Answer Germany for one address and nothing for any other.
             *
             * @param  string|null  $ip
             * @return string|null
             */
            public function country(?string $ip): ?string
            {
                return $ip === '203.0.113.9' ? 'DE' : null;
            }

            /**
             * Answer Berlin for the same address.
             *
             * @param  string|null  $ip
             * @return array{country: string|null, region: string|null, city: string|null}
             */
            public function location(?string $ip): array
            {
                return $ip === '203.0.113.9' ? ['country' => 'DE', 'region' => 'Land Berlin', 'city' => 'Berlin'] : ['country' => null, 'region' => null, 'city' => null];
            }
        });
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['domains' => ['example.com'], 'timezone' => 'UTC', 'verified_at' => now()]);
        $event = fn (string $visitor): array => ['id' => (string) Str::uuid(), 'type' => 'pageview', 'path' => '/', 'visitor' => $visitor];

        $this->withHeader('Origin', 'https://example.com')->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson("/api/v1/collect/{$site->public_id}", ['events' => [$event('berlin')]])->assertAccepted();
        $this->withHeader('Origin', 'https://example.com')->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson("/api/v1/collect/{$site->public_id}", ['events' => [$event('elsewhere')]])->assertAccepted();

        $this->assertSame(['DE', null], AnalyticsEvent::query()->orderBy('id')->pluck('country_code')->all());
        $this->assertContains('DE', AnalyticsVisit::query()->pluck('country_code')->all());
        $this->assertSame(['Land Berlin', 'Berlin'], [AnalyticsVisit::query()->where('country_code', 'DE')->value('region'), AnalyticsVisit::query()->where('country_code', 'DE')->value('city')]);
        app(RefreshRecentAggregates::class)->handle();
        $this->assertDatabaseHas(AnalyticsDailyAggregate::class, ['site_id' => $site->id, 'dimension' => 'country', 'dimension_value' => 'DE', 'visits' => 1]);

        $report = app(AnalyticsReportQuery::class)->handle($site, 7);
        $this->assertEqualsCanonicalizing([['label' => 'DE', 'value' => 1], ['label' => 'Unknown', 'value' => 1]], $report['countries']);
        $this->assertSame('1', app(AnalyticsReportQuery::class)->handle($site, 7, ['country' => 'DE'])['metrics'][0]['value']);
        $this->assertSame([['label' => 'Berlin', 'value' => 1]], $report['cities']);
        $this->assertSame([['label' => 'Land Berlin', 'value' => 1]], $report['regions']);
        $this->assertSame('1', app(AnalyticsReportQuery::class)->handle($site, 7, ['city' => 'Berlin'])['metrics'][0]['value']);

        $this->actingAs($owner)->get("/projects/{$project->id}/analytics?days=7")->assertOk()
            ->assertSee(__('Countries'))->assertSee('🇩🇪 Germany')->assertSee('country=DE', false);
        $this->actingAs($owner)->get("/projects/{$project->id}/analytics?days=7&country=de")->assertOk()->assertSee('name="country" value="DE"', false);
    }

    /**
     * Check that the real lookup answers null without its database, and a bad download never replaces it.
     *
     * @return void
     */
    public function test_the_lookup_copes_without_a_database_and_bad_downloads_are_refused(): void
    {
        $path = storage_path('framework/testing/geoip-'.Str::random(8).'.mmdb');
        config(['analytics.geoip_database' => $path]);
        $this->assertNull((new DbIpCountryLookup)->country('8.8.8.8'));
        $this->assertNull((new DbIpCountryLookup)->country('10.0.0.1'));

        Http::fake(['download.db-ip.com/*' => Http::response((string) gzencode('not a database'))]);
        $this->assertSame(1, Artisan::call('analytics:update-geoip'));
        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path.'.download');
        Http::assertSentCount(2);
    }

    /**
     * Check country names and flags.
     *
     * @return void
     */
    public function test_country_labels(): void
    {
        $this->assertSame('🇫🇷 France', Country::label('FR'));
        $this->assertSame('Unknown', Country::label('Unknown'));
    }
}
