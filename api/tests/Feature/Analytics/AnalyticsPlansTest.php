<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\PruneAnalyticsData;
use App\Actions\Analytics\SaveSite;
use App\Data\Analytics\SiteDetails;
use App\Exceptions\AnalyticsRuleViolation;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class AnalyticsPlansTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_the_free_plan_allows_three_sites_across_the_account(): void
    {
        $project = Project::factory()->withServices(['analytics'])->create();
        $owner = $this->ownerOf($project);
        $save = app(SaveSite::class);
        foreach (['one', 'two', 'three'] as $name) {
            $save->handle($owner, $project, new SiteDetails($name, ["{$name}.example.com"]));
        }
        try {
            $save->handle($owner, $project, new SiteDetails('four', ['four.example.com']));
            $this->fail('A fourth site was allowed on Free.');
        } catch (AnalyticsRuleViolation $violation) {
            $this->assertSame('name', $violation->field);
        }

        $this->onTier($project, 'analytics', 'pro');
        $save->handle($owner, $project, new SiteDetails('four', ['four.example.com']));
        $this->assertSame(4, AnalyticsSite::query()->count());
    }

    public function test_events_are_kept_as_long_as_each_accounts_plan_says(): void
    {
        [$free, $business] = [Project::factory()->withServices(['analytics'])->create(), Project::factory()->withServices(['analytics'])->create()];
        $this->onTier($business, 'analytics', 'business');
        foreach ([$free, $business] as $project) {
            $site = AnalyticsSite::factory()->create(['project_id' => $project->id]);
            foreach ([30, 200, 800] as $days) {
                (new AnalyticsEvent)->forceFill(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'pageview', 'occurred_at' => now()->subDays($days), 'received_at' => now(), 'path' => '/'])->save();
            }
        }

        app(PruneAnalyticsData::class)->handle();

        $kept = fn (Project $project): int => AnalyticsEvent::query()->whereIn('site_id', AnalyticsSite::query()->where('project_id', $project->id)->select('id'))->count();
        $this->assertSame([1, 2], [$kept($free), $kept($business)]);
    }
}
