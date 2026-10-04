<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Contracts\Analytics\GoogleAnalyticsData;
use App\Models\Account;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsImport;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Services\Analytics\GoogleAnalytics;
use App\Services\Analytics\GoogleOAuth;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class GoogleAnalyticsImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check the whole import: connect, choose a property and dates, import only the days before the site's own
     * data, show them in reports, and remove them again.
     *
     * @return void
     */
    public function test_history_is_imported_before_the_sites_own_data_and_can_be_removed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00', 'UTC'));
        $this->app->instance(GoogleAnalyticsData::class, new class implements GoogleAnalyticsData
        {
            /**
             * Say Google sign-in is set up.
             *
             * @return bool
             */
            public function configured(): bool
            {
                return true;
            }

            /**
             * Point at a fake Google.
             *
             * @param  string  $state
             * @return string
             */
            public function authorizationUrl(string $state): string
            {
                return 'https://accounts.google.test/auth?state='.$state;
            }

            /**
             * Answer a refresh token for the right code.
             *
             * @param  string  $code
             * @return string
             */
            public function exchange(string $code): string
            {
                return $code === 'good' ? 'refresh-1' : throw new RuntimeException('Bad code');
            }

            /**
             * List one property.
             *
             * @param  string  $refreshToken
             * @return list<array{id: string, name: string}>
             */
            public function properties(string $refreshToken): array
            {
                return [['id' => '123', 'name' => 'Acme · Shop GA4']];
            }

            /**
             * Answer two days of history for the site and for pages.
             *
             * @param  string  $refreshToken
             * @param  string  $property
             * @param  CarbonImmutable  $from
             * @param  CarbonImmutable  $until
             * @param  string|null  $dimension
             * @return list<array{date: string, value: string|null, pageviews: int, visits: int, visitors: int, bounces: int, duration: int}>
             */
            public function daily(string $refreshToken, string $property, CarbonImmutable $from, CarbonImmutable $until, ?string $dimension): array
            {
                $rows = [];
                foreach (['2026-09-01', '2026-09-02', '2026-09-10'] as $date) {
                    if ($date >= $from->toDateString() && $date <= $until->toDateString()) {
                        $rows[] = ['date' => $date, 'value' => match ($dimension) {
                            null => null, 'deviceCategory' => 'desktop', 'sessionCampaignName' => '(organic)', default => '/pricing'
                        }, 'pageviews' => 100, 'visits' => 40, 'visitors' => 30, 'bounces' => 10, 'duration' => 1200];
                    }
                }

                return $rows;
            }
        });
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['timezone' => 'UTC']);
        AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => CarbonImmutable::parse('2026-09-05 10:00', 'UTC'), 'received_at' => now(), 'path' => '/', 'visitor_hash' => 'own']);
        $page = "/api/app/projects/{$project->id}/analytics/sites/{$site->id}";

        $this->actingAs($owner)->postJson("{$page}/imports/google")->assertSuccessful()->assertJsonPath('redirect', fn ($url): bool => str_contains((string) $url, 'accounts.google.test'));
        $state = session('google-analytics.connect')['state'];
        $this->actingAs($owner)->get('/analytics/google-analytics/callback?state=wrong&code=good')->assertForbidden();
        $this->actingAs($owner)->postJson("{$page}/imports/google");
        $state = session('google-analytics.connect')['state'];
        $this->actingAs($owner)->get("/analytics/google-analytics/callback?state={$state}&code=good")->assertRedirectContains("/projects/{$project->id}/analytics/sites/{$site->id}?notice=");
        $import = AnalyticsImport::query()->sole();
        $this->assertSame(['connected', 'refresh-1'], [$import->status, $import->refresh_token]);
        $this->actingAs($owner)->getJson($page)->assertOk()->assertJsonHasText('Acme · Shop GA4');

        $this->actingAs($owner)->postJson("{$page}/imports/{$import->id}", ['property' => '999', 'from' => '2026-08-01', 'until' => '2026-09-19'])->assertJsonValidationErrors('property');
        $this->actingAs($owner)->postJson("{$page}/imports/{$import->id}", ['property' => '123', 'from' => '2026-08-01', 'until' => '2026-09-19'])->assertJsonRedirect($page);

        $import->refresh();
        $this->assertSame(['done', 2, '2026-09-04', null], [$import->status, $import->days_imported, $import->until_date?->toDateString(), $import->refresh_token], 'Days from the site’s own data on are left out, and the connection is forgotten.');
        $this->assertSame('2026-09-04', $site->refresh()->imported_until?->toDateString());
        $this->assertTrue(AnalyticsDailyAggregate::query()->where('dimension', 'device')->where('dimension_value', 'Desktop')->exists());
        $this->assertFalse(AnalyticsDailyAggregate::query()->where('dimension', 'campaign')->exists(), 'GA’s placeholder campaigns aren’t campaigns.');

        $report = app(AnalyticsReportQuery::class)->handle($site, 30);
        $this->assertSame('200', $report['metrics'][0]['value'], 'Imported pageviews show in a 30-day report reaching back before the site’s own data.');
        $this->assertSame([['label' => '/pricing', 'value' => 200]], $report['pages']);

        $this->actingAs($owner)->deleteJson("{$page}/imports/{$import->id}")->assertJsonRedirect($page);
        $this->assertSame(0, AnalyticsDailyAggregate::query()->whereDate('local_date', '<=', '2026-09-04')->count());
        $this->assertNull($site->refresh()->imported_until);
    }

    /**
     * Check the real client reads properties and pages through reports.
     *
     * @return void
     */
    public function test_the_client_reads_properties_and_daily_rows(): void
    {
        config(['services.google_search_console.client_id' => 'id', 'services.google_search_console.client_secret' => 'secret']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access']),
            'analyticsadmin.googleapis.com/*' => Http::response(['accountSummaries' => [['displayName' => 'Acme', 'propertySummaries' => [['property' => 'properties/42', 'displayName' => 'Shop']]]]]),
            'analyticsdata.googleapis.com/*' => Http::response(['rowCount' => 1, 'rows' => [['dimensionValues' => [['value' => '20260901'], ['value' => '/']], 'metricValues' => [['value' => '10'], ['value' => '4'], ['value' => '3'], ['value' => '1'], ['value' => '90']]]]]),
        ]);
        $google = new GoogleAnalytics(new GoogleOAuth);
        $this->assertSame([['id' => '42', 'name' => 'Acme · Shop']], $google->properties('refresh'));
        $this->assertSame([['date' => '2026-09-01', 'value' => '/', 'pageviews' => 10, 'visits' => 4, 'visitors' => 3, 'bounces' => 3, 'duration' => 90]],
            $google->daily('refresh', '42', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'), 'pagePath'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'properties/42:runReport') && $request['dimensions'][1]['name'] === 'pagePath');
    }
}
