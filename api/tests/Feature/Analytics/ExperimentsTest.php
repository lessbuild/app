<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\RebuildSiteReports;
use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsExperiment;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ExperimentResultsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExperimentsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check an owner starts an A/B test, the tracker's exposures reach the collector, each variant's conversions and
     * lift are counted against the goal, a clear winner is significant, and stopping and deleting work.
     *
     * @return void
     */
    public function test_an_ab_test_finds_the_winning_variant(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00', 'UTC'));
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['domains' => ['example.com'], 'timezone' => 'UTC', 'verified_at' => now()]);
        $goal = $site->goals()->create(['name' => 'Signed up', 'kind' => 'event', 'match_type' => 'exact', 'match_value' => 'signup', 'active' => true]);
        $goal->versions()->create(['kind' => 'event', 'match_type' => 'exact', 'match_value' => 'signup', 'effective_from' => now()->subYear()]);
        $base = "/api/app/projects/{$project->id}/analytics/sites/{$site->id}/experiments";

        $this->actingAs($owner)->postJson($base, ['name' => 'Bolder headline', 'key' => 'Headline', 'variants' => 'control, bold', 'goal_id' => $goal->id])->assertSuccessful();
        $this->actingAs($owner)->postJson($base, ['name' => 'Again', 'key' => 'headline', 'variants' => 'a, b'])->assertJsonValidationErrors('key');
        $this->actingAs($owner)->postJson($base, ['name' => 'One', 'key' => 'solo', 'variants' => 'only'])->assertJsonValidationErrors('variants');
        $experiment = AnalyticsExperiment::query()->sole();
        $this->assertSame(['headline', ['control', 'bold']], [$experiment->key, $experiment->variants]);

        $this->withHeaders(['Origin' => 'https://example.com'])->postJson("/api/v1/collect/{$site->public_id}", ['events' => [
            ['id' => (string) Str::uuid(), 'type' => 'experiment', 'path' => '/', 'properties' => ['experiment' => 'headline', 'variant' => 'bold']],
        ]])->assertAccepted();
        $this->flushHeaders();
        $this->assertSame(['experiment' => 'headline', 'variant' => 'bold'], AnalyticsEvent::query()->where('type', 'experiment')->sole()->properties);
        AnalyticsEvent::query()->delete();

        // 100 visitors each: 10 of the control's sign up, 30 of the bold headline's.
        $this->travel(1)->minutes();
        foreach (['control' => 10, 'bold' => 30] as $variant => $converted) {
            for ($i = 0; $i < 100; $i++) {
                $visitor = "{$variant}-{$i}";
                AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'experiment', 'occurred_at' => now(), 'received_at' => now(), 'path' => '/', 'visitor_hash' => $visitor, 'session_id' => $visitor, 'properties' => ['experiment' => 'headline', 'variant' => $variant]]);
                if ($i < $converted) {
                    AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => 'event', 'occurred_at' => now(), 'received_at' => now(), 'path' => '/signup', 'visitor_hash' => $visitor, 'session_id' => $visitor, 'properties' => ['name' => 'signup']]);
                }
            }
        }
        app(RebuildSiteReports::class)->handle($site);

        [$control, $bold] = app(ExperimentResultsQuery::class)->handle($experiment->refresh());
        $this->assertSame(['control', 100, 10, 10.0, null, null, false], array_values($control));
        $this->assertSame(['bold', 100, 30, 30.0, 200.0], array_slice(array_values($bold), 0, 5));
        $this->assertTrue($bold['significant']);
        $this->assertLessThan(0.001, $bold['p_value']);

        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/analytics/explore?tab=experiments")->assertOk()
            ->assertJsonHasText('Bolder headline')->assertJsonPath('result.0.variants.1.lift', fn ($lift): bool => abs((float) $lift - 200.0) < 0.01)->assertJsonPath('result.0.variants.1.significant', true);
        $this->actingAs($owner)->putJson("{$base}/{$experiment->id}")->assertSuccessful();
        $this->assertSame('stopped', $experiment->refresh()->status);
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->deleteJson("{$base}/{$experiment->id}")->assertNotFound();
        $this->actingAs($owner)->deleteJson("{$base}/{$experiment->id}")->assertSuccessful();
        $this->assertSame(0, AnalyticsExperiment::query()->count());
    }
}
