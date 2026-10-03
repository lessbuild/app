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
use App\Queries\Analytics\InsightsQuery;
use App\Queries\Analytics\ItemsQuery;
use App\Queries\Analytics\PathExplorationQuery;
use App\Queries\Analytics\PropertyBreakdownQuery;
use App\Queries\Analytics\RetentionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExploreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The site under test.
     *
     * @var AnalyticsSite
     */
    private AnalyticsSite $site;

    /**
     * The account owner.
     *
     * @var User
     */
    private User $owner;

    /**
     * Create a site with a week of traffic.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00', 'UTC'));
        $this->owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($this->owner))->withServices(['analytics'])->create();
        $this->site = AnalyticsSite::factory()->for($project)->create(['timezone' => 'UTC', 'custom_properties' => ['plan']]);
    }

    /**
     * Record one event.
     *
     * @param  string  $at
     * @param  array<string, mixed>  $attributes
     * @return void
     */
    private function event(string $at, array $attributes = []): void
    {
        AnalyticsEvent::create(['site_id' => $this->site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => CarbonImmutable::parse($at, 'UTC'), 'received_at' => CarbonImmutable::parse($at, 'UTC'), 'path' => '/', ...$attributes]);
    }

    /**
     * Check path exploration, property breakdowns, items and the page's tabs.
     *
     * @return void
     */
    public function test_paths_properties_and_items(): void
    {
        foreach (['a', 'b', 'c'] as $index => $visitor) {
            $this->event("2026-09-22 10:0{$index}", ['session_id' => $visitor, 'path' => '/']);
            $this->event("2026-09-22 10:1{$index}", ['session_id' => $visitor, 'path' => '/pricing']);
            if ($visitor !== 'c') {
                $this->event("2026-09-22 10:2{$index}", ['session_id' => $visitor, 'path' => '/signup']);
                $this->event("2026-09-22 10:3{$index}", ['session_id' => $visitor, 'type' => 'event', 'path' => '/signup', 'properties' => ['name' => 'purchase', 'revenue' => 30, 'currency' => 'USD', 'props' => ['plan' => $visitor === 'a' ? 'pro' : 'team'], 'items' => [['id' => 'sku-1', 'name' => 'Pro plan', 'category' => 'Plans', 'price' => 25, 'quantity' => 1], ['id' => 'x', 'name' => 'Add-on', 'category' => null, 'price' => 5, 'quantity' => 2]]]]);
            }
        }
        $period = ReportPeriod::lastDays('UTC', 7);

        $paths = app(PathExplorationQuery::class)->handle($this->site, $period, '/pricing');
        $this->assertSame(3, $paths['views']);
        $this->assertSame([['label' => '/', 'value' => 3]], $paths['previous']);
        $this->assertSame([['label' => '/signup', 'value' => 2], ['label' => '(exit)', 'value' => 1]], $paths['next']);

        $breakdown = app(PropertyBreakdownQuery::class)->handle($this->site, $period, 'purchase', 'plan');
        $this->assertSame([['label' => 'purchase', 'value' => 2]], $breakdown['events']);
        $this->assertSame([['label' => 'pro', 'events' => 1, 'visitors' => 0, 'revenue' => 30.0], ['label' => 'team', 'events' => 1, 'visitors' => 0, 'revenue' => 30.0]], $breakdown['values']);
        $this->assertSame([], app(PropertyBreakdownQuery::class)->handle($this->site, $period, 'purchase', 'secret')['values'], 'Only kept properties.');

        $items = app(ItemsQuery::class)->handle($this->site, $period);
        $this->assertSame(['item' => 'Pro plan', 'category' => 'Plans', 'quantity' => 2, 'orders' => 2, 'revenue' => 50.0, 'currency' => 'USD'], $items[0]);
        $this->assertSame(['item' => 'Add-on', 'category' => null, 'quantity' => 4, 'orders' => 2, 'revenue' => 20.0, 'currency' => 'USD'], $items[1]);

        $base = "/projects/{$this->site->project_id}/analytics/explore";
        $this->actingAs($this->owner)->get($base)->assertOk()->assertSee(__('What changed'));
        $this->actingAs($this->owner)->get("{$base}?tab=paths&path=/pricing")->assertOk()->assertSee(__('Went to'))->assertSee('/signup');
        $this->actingAs($this->owner)->get("{$base}?tab=properties&event=purchase&property=plan")->assertOk()->assertSee('team');
        $this->actingAs($this->owner)->get("{$base}?tab=items")->assertOk()->assertSee('Pro plan')->assertSee('USD 50.00');
        $this->actingAs($this->owner)->get("{$base}?tab=retention")->assertOk()->assertSee('data-retention');
    }

    /**
     * Check automatic insights and retention cohorts.
     *
     * @return void
     */
    public function test_insights_and_retention(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->event('2026-09-21 09:00', ['path' => '/launch', 'visitor_hash' => "now-{$i}", 'returning_hash' => $i < 4 ? "r{$i}" : null]);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->event('2026-09-12 09:00', ['path' => '/old', 'visitor_hash' => "then-{$i}"]);
        }
        $this->event('2026-09-14 09:00', ['returning_hash' => 'r0']);
        $this->event('2026-09-15 09:00', ['returning_hash' => 'r9']);
        $this->event('2026-09-22 09:00', ['returning_hash' => 'r9']);
        app(RebuildSiteReports::class)->handle($this->site);

        $insights = app(InsightsQuery::class)->handle($this->site, ReportPeriod::lastDays('UTC', 7));
        $this->assertContains(['tone' => 'info', 'dimension' => 'Page', 'label' => '/launch', 'current' => 12, 'previous' => 0, 'change' => 'New'], $insights);
        $this->assertContains(['tone' => 'warning', 'dimension' => 'Page', 'label' => '/old', 'current' => 0, 'previous' => 20, 'change' => '-100%'], $insights);

        $retention = app(RetentionQuery::class)->handle($this->site, 3);
        $this->assertTrue($retention['tracked']);
        [$first, $second, $third] = $retention['cohorts'];
        $this->assertSame(['2026-09-07', 0], [$first['week']->toDateString(), $first['size']]);
        $this->assertSame(['2026-09-14', 2, 100, null], [$second['week']->toDateString(), $second['size'], $second['returned'][0], $second['returned'][1]], 'r0 and r9 were first seen that week and both came back the next.');
        $this->assertSame(['2026-09-21', 3, null], [$third['week']->toDateString(), $third['size'], $third['returned'][0]], 'r1 to r3 are new this week.');
    }
}
