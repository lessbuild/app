<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Contracts\Analytics\CountryLookup;
use App\Models\Account;
use App\Models\AnalyticsAnnotation;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Support\Analytics\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VisitorDetailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that the tracker's extra detail is kept (UTM term and content, channel, screen size, versions,
     * engagement, custom properties and items), carried onto visits, ranked and filterable.
     *
     * @return void
     */
    public function test_extra_detail_is_collected_ranked_and_filterable(): void
    {
        $site = AnalyticsSite::factory()->create(['domains' => ['example.com'], 'timezone' => 'UTC', 'verified_at' => now()]);
        $site->forceFill(['custom_properties' => ['plan', 'trial', 'contact']])->save();
        $base = ['visitor' => 'v1', 'session' => 's1', 'returning' => 'browser-1', 'browser' => 'Chrome', 'browser_version' => '128', 'os' => 'iOS', 'os_version' => '17.4', 'screen' => 390];
        $this->withHeader('Origin', 'https://example.com')->postJson("/api/v1/collect/{$site->public_id}", ['events' => [
            ['id' => (string) Str::uuid(), 'type' => 'pageview', 'path' => '/pricing', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_term' => 'cheap hosting', 'utm_content' => 'ad-b', ...$base],
            ['id' => (string) Str::uuid(), 'type' => 'engagement', 'path' => '/pricing', 'properties' => ['scroll' => 80, 'engaged_ms' => 30_000, 'extra' => 'dropped'], ...$base],
            ['id' => (string) Str::uuid(), 'type' => 'event', 'path' => '/pricing', 'properties' => [
                'name' => 'purchase', 'revenue' => 30, 'plan' => 'pro', 'Bad-Key' => 'x', 'trial' => true, 'unlisted' => 'dropped', 'contact' => 'me@example.com',
                'items' => [['id' => 'sku-1', 'name' => 'Pro plan', 'price' => '25.50', 'quantity' => 1], ['name' => 'Add-on', 'price' => 5], ['price' => 1]],
            ], ...$base],
            ['id' => (string) Str::uuid(), 'type' => 'pageview', 'path' => '/', 'referrer_host' => 'www.example.com', ...[...$base, 'visitor' => 'v2', 'session' => 's2', 'screen' => 1920, 'screen_bogus' => 1]],
        ]])->assertAccepted();
        app(RebuildSiteReports::class)->handle($site);

        $pageview = AnalyticsEvent::query()->where('path', '/pricing')->where('type', 'pageview')->sole();
        $this->assertSame(['cheap hosting', 'ad-b', 'Paid Search', 'Mobile', '128', '17.4'], [$pageview->utm_term, $pageview->utm_content, $pageview->channel, $pageview->screen_size, $pageview->browser_version, $pageview->os_version]);
        $this->assertSame(64, strlen((string) $pageview->returning_hash));
        $this->assertSame(1, AnalyticsEvent::query()->whereNotNull('returning_hash')->distinct()->count('returning_hash'), 'One browser, one stable hash.');
        $this->assertSame(['scroll' => 80, 'engaged_ms' => 30_000], AnalyticsEvent::query()->where('type', 'engagement')->sole()->properties);
        $purchase = AnalyticsEvent::query()->where('type', 'event')->sole()->properties ?? [];
        $this->assertSame(['plan' => 'pro', 'trial' => 'true'], $purchase['props']);
        $this->assertCount(2, $purchase['items']);
        $this->assertSame(['id' => 'sku-1', 'name' => 'Pro plan', 'category' => null, 'price' => 25.5, 'quantity' => 1], $purchase['items'][0]);
        $this->assertSame('Direct', AnalyticsEvent::query()->where('path', '/')->sole()->channel, 'A referrer from the site itself is no referrer.');
        $this->assertSame('Paid Search', AnalyticsVisit::query()->where('session_id', 's1')->value('entry_channel'));

        $report = app(AnalyticsReportQuery::class)->handle($site, 7);
        $this->assertEqualsCanonicalizing([['label' => 'Direct', 'value' => 1], ['label' => 'Paid Search', 'value' => 1]], $report['channels']);
        $this->assertSame([['label' => 'cheap hosting', 'value' => 1]], $report['terms']);
        $this->assertSame([['label' => 'ad-b', 'value' => 1]], $report['contents']);
        $this->assertEqualsCanonicalizing([['label' => 'Desktop', 'value' => 1], ['label' => 'Mobile', 'value' => 1]], $report['screenSizes']);
        $this->assertSame([['label' => 'Chrome 128', 'value' => 2]], $report['browserVersions']);
        $this->assertContains(['path' => '/pricing', 'pageviews' => 1, 'seconds' => 30, 'scroll' => 80], $report['engagement']);
        $this->assertSame('1', app(AnalyticsReportQuery::class)->handle($site, 7, ['channel' => 'Paid Search'])['metrics'][0]['value']);
        $this->assertSame('1', app(AnalyticsReportQuery::class)->handle($site, 7, ['screen' => 'Desktop'])['metrics'][0]['value']);
        $this->assertSame('1', app(AnalyticsReportQuery::class)->handle($site, 7, ['term' => 'cheap hosting'])['metrics'][2]['value']);
    }

    /**
     * Check the channel grouping for the common cases.
     *
     * @return void
     */
    public function test_channels_follow_the_usual_grouping(): void
    {
        $this->assertSame('Direct', Channel::for(null, null, null, null));
        $this->assertSame('Organic Search', Channel::for(null, null, null, 'www.google.co.uk'));
        $this->assertSame('Paid Social', Channel::for('facebook', 'cpc', null, null));
        $this->assertSame('Email', Channel::for('newsletter', null, null, null));
        $this->assertSame('Organic Social', Channel::for(null, null, null, 't.co'));
        $this->assertSame('Organic Video', Channel::for(null, null, null, 'youtube.com'));
        $this->assertSame('Referral', Channel::for(null, null, null, 'blog.example'));
        $this->assertSame('Unassigned', Channel::for('partner', null, null, null));
        $this->assertSame('Direct', Channel::for(null, null, null, 'shop.example.com', ['example.com']));
    }

    /**
     * Check that events sent through a site's own proxy count the visitor's address from X-Forwarded-For, and that
     * notes are added to and removed from the chart.
     *
     * @return void
     */
    public function test_proxied_events_use_the_forwarded_address_and_notes_mark_the_chart(): void
    {
        $this->app->instance(CountryLookup::class, new class implements CountryLookup
        {
            /**
             * Answer France for one address.
             *
             * @param  string|null  $ip
             * @return string|null
             */
            public function country(?string $ip): ?string
            {
                return $ip === '203.0.113.50' ? 'FR' : null;
            }

            /**
             * Answer France for the same address.
             *
             * @param  string|null  $ip
             * @return array{country: string|null, region: string|null, city: string|null}
             */
            public function location(?string $ip): array
            {
                return ['country' => $this->country($ip), 'region' => null, 'city' => null];
            }
        });
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['domains' => ['example.com'], 'timezone' => 'UTC', 'verified_at' => now()]);
        $this->withHeaders(['Origin' => 'https://example.com', 'X-Forwarded-For' => '10.0.0.1, 203.0.113.50'])
            ->postJson("/api/v1/collect/{$site->public_id}", ['events' => [['id' => (string) Str::uuid(), 'type' => 'pageview', 'path' => '/']]])->assertAccepted();
        $this->assertSame('FR', AnalyticsEvent::query()->sole()->country_code);

        $this->flushHeaders();
        $this->actingAs($owner)->postJson("/api/app/projects/{$project->id}/analytics/sites/{$site->id}/annotations", ['date' => now()->toDateString(), 'text' => 'Launched on Product Hunt'])->assertSuccessful();
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/analytics?days=7")->assertOk()->assertJsonHasText('Launched on Product Hunt')->assertJsonPath('canManage', true);
        $note = AnalyticsAnnotation::query()->sole();
        $this->actingAs($owner)->deleteJson("/api/app/projects/{$project->id}/analytics/sites/{$site->id}/annotations/{$note->id}")->assertSuccessful();
        $this->assertSame(0, AnalyticsAnnotation::query()->count());
    }
}
