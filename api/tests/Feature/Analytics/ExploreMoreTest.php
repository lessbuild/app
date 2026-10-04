<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Data\Analytics\ReportPeriod;
use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AttributionQuery;
use App\Queries\Analytics\ClickMapQuery;
use App\Queries\Analytics\FormsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExploreMoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check attribution credits first and last touch, click maps place and rank clicks, and form analytics finds where
     * people give up, including through the collector and the Explore page.
     *
     * @return void
     */
    public function test_attribution_clicks_and_forms(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00', 'UTC'));
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['domains' => ['example.com'], 'timezone' => 'UTC', 'verified_at' => now()]);
        $goal = $site->goals()->create(['name' => 'Bought', 'kind' => 'event', 'match_type' => 'exact', 'match_value' => 'purchase', 'active' => true]);
        $goal->versions()->create(['kind' => 'event', 'match_type' => 'exact', 'match_value' => 'purchase', 'effective_from' => now()->subYear()]);
        $event = fn (string $at, array $attributes) => AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => CarbonImmutable::parse($at, 'UTC'), 'received_at' => now(), 'path' => '/', ...$attributes]);
        // Visitor A found the site through search on Monday and came back from the newsletter to buy on Tuesday.
        $event('2026-09-21 09:00', ['session_id' => 'a1', 'visitor_hash' => 'a-mon', 'returning_hash' => 'A', 'channel' => 'Organic Search']);
        $event('2026-09-22 09:00', ['session_id' => 'a2', 'visitor_hash' => 'a-tue', 'returning_hash' => 'A', 'channel' => 'Email', 'utm_campaign' => 'launch']);
        $event('2026-09-22 09:05', ['session_id' => 'a2', 'visitor_hash' => 'a-tue', 'returning_hash' => 'A', 'type' => 'event', 'properties' => ['name' => 'purchase', 'revenue' => 40, 'currency' => 'USD']]);
        // Visitor B came straight in and bought in the same visit.
        $event('2026-09-22 10:00', ['session_id' => 'b1', 'visitor_hash' => 'b', 'channel' => 'Direct']);
        $event('2026-09-22 10:03', ['session_id' => 'b1', 'visitor_hash' => 'b', 'type' => 'event', 'properties' => ['name' => 'purchase', 'revenue' => 10, 'currency' => 'USD']]);
        app(RebuildSiteReports::class)->handle($site);
        $period = ReportPeriod::lastDays('UTC', 7);

        $rows = app(AttributionQuery::class)->handle($site, $period, 'channel');
        $this->assertContains(['label' => 'Organic Search', 'first' => 1, 'last' => 0, 'first_revenue' => '$40.00', 'last_revenue' => null], $rows, 'Search found them; email closed the sale.');
        $this->assertContains(['label' => 'Email', 'first' => 0, 'last' => 1, 'first_revenue' => null, 'last_revenue' => '$40.00'], $rows);
        $this->assertContains(['label' => 'Direct', 'first' => 1, 'last' => 1, 'first_revenue' => '$10.00', 'last_revenue' => '$10.00'], $rows);

        $send = fn (array $events) => $this->withHeaders(['Origin' => 'https://example.com'])->postJson("/api/v1/collect/{$site->public_id}", ['events' => array_map(fn (array $e): array => ['id' => (string) Str::uuid(), ...$e], $events)]);
        $send([
            ['type' => 'click', 'path' => '/pricing', 'session' => 's1', 'properties' => ['target' => 'button "Buy"', 'x' => 50.5, 'y' => 30]],
            ['type' => 'click', 'path' => '/pricing', 'session' => 's1', 'properties' => ['target' => 'button "Buy"', 'x' => 51, 'y' => 31]],
            ['type' => 'click', 'path' => '/pricing', 'session' => 's1', 'properties' => ['target' => 'a#faq', 'x' => 150, 'y' => 90]],
            ['type' => 'form', 'path' => '/signup', 'session' => 's1', 'properties' => ['form' => 'signup', 'action' => 'focus', 'field' => 'email']],
            ['type' => 'form', 'path' => '/signup', 'session' => 's1', 'properties' => ['form' => 'signup', 'action' => 'focus', 'field' => 'password']],
            ['type' => 'form', 'path' => '/signup', 'session' => 's1', 'properties' => ['form' => 'signup', 'action' => 'submit']],
            ['type' => 'form', 'path' => '/signup', 'session' => 's2', 'properties' => ['form' => 'signup', 'action' => 'focus', 'field' => 'email']],
            ['type' => 'form', 'path' => '/signup', 'session' => 's2', 'properties' => ['form' => 'signup', 'action' => 'focus', 'field' => 'password']],
            ['type' => 'form', 'path' => '/signup', 'session' => 's3', 'properties' => ['form' => 'signup', 'action' => 'focus', 'field' => 'email', 'value' => 'secret']],
        ])->assertAccepted();
        $this->flushHeaders();
        app(RebuildSiteReports::class)->handle($site);

        $clicks = app(ClickMapQuery::class)->handle($site, $period, '/pricing');
        $this->assertSame([['label' => '/pricing', 'value' => 3]], $clicks['pages']);
        $this->assertSame(['label' => 'button "Buy"', 'value' => 2], $clicks['targets'][0]);
        $this->assertContains(['x' => 100.0, 'y' => 90.0], $clicks['points'], 'Positions are kept within the page.');

        $forms = app(FormsQuery::class)->handle($site, $period);
        $this->assertSame(['form' => 'signup', 'started' => 3, 'submitted' => 1], array_slice($forms[0], 0, 3));
        $this->assertSame([['field' => 'email', 'reached' => 3, 'left' => 1], ['field' => 'password', 'reached' => 2, 'left' => 1]], $forms[0]['fields']);
        $this->assertStringNotContainsString('secret', (string) json_encode(AnalyticsEvent::query()->where('type', 'form')->pluck('properties')), 'Values are never stored.');

        $page = "/api/app/projects/{$project->id}/analytics/explore";
        $this->actingAs($owner)->getJson("{$page}?tab=attribution")->assertOk()->assertJsonHasText('Organic Search')->assertJsonPath('by', 'channel');
        $this->actingAs($owner)->getJson("{$page}?tab=clicks&path=/pricing")->assertOk()->assertJsonHasText('button "Buy"')->assertJsonPath('result.points', fn ($points): bool => $points !== []);
        $this->actingAs($owner)->getJson("{$page}?tab=forms")->assertOk()->assertJsonHasText('signup')->assertJsonPath('tab', 'forms');
    }
}
