<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsFunnel;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FunnelsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that a funnel counts visitors who took its steps in order, and that funnels are managed from the page.
     *
     * @return void
     */
    public function test_a_funnel_counts_visitors_through_its_steps_in_order(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for(Account::factory()->withMember($owner))->withServices(['analytics'])->create();
        $site = AnalyticsSite::factory()->for($project)->create(['name' => 'Shop']);
        $journey = function (string $session, array $steps) use ($site): void {
            foreach ($steps as $minutes => $step) {
                AnalyticsEvent::create(['site_id' => $site->id, 'event_id' => (string) Str::uuid(), 'type' => str_starts_with($step, '/') ? 'pageview' : 'event',
                    'path' => str_starts_with($step, '/') ? $step : '/checkout', 'properties' => str_starts_with($step, '/') ? null : ['name' => $step],
                    'occurred_at' => now()->subHours(2)->addMinutes($minutes), 'received_at' => now(), 'session_id' => $session]);
            }
        };
        $journey('a', ['/pricing', '/products/shoes', 'purchase']);   // all three
        $journey('b', ['/pricing', '/products/hat']);                 // two
        $journey('c', ['/products/shoes', '/pricing']);               // out of order: only the first step
        $journey('d', ['/about']);                                     // none

        $base = "/api/app/projects/{$project->id}/analytics/funnels";
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonCount(0, 'funnels');
        $store = "/api/app/projects/{$project->id}/analytics/sites/{$site->id}/funnels";
        $this->actingAs($owner)->postJson($store, ['name' => 'One step', 'steps' => [['kind' => 'pageview', 'match' => 'exact', 'value' => '/pricing']]])->assertJsonValidationErrors('steps');
        $this->actingAs($owner)->postJson($store, ['name' => 'Bad', 'steps' => [['kind' => 'pageview', 'value' => 'pricing'], ['kind' => 'pageview', 'value' => '/x']]])->assertJsonValidationErrors('steps');
        $this->actingAs($owner)->postJson($store, ['name' => 'Checkout', 'steps' => [
            ['kind' => 'pageview', 'match' => 'exact', 'value' => '/pricing'],
            ['kind' => 'pageview', 'match' => 'prefix', 'value' => '/products/'],
            ['kind' => 'event', 'match' => 'exact', 'value' => 'purchase'],
            ['kind' => 'pageview', 'match' => 'exact', 'value' => ''],
        ]])->assertJsonRedirect("{$base}?site={$site->id}");
        $funnel = AnalyticsFunnel::query()->sole();
        $this->assertCount(3, $funnel->steps);

        $this->actingAs($owner)->getJson($base)->assertOk()
            ->assertJsonPath('funnels.0.report', [
                ['label' => 'Page /pricing', 'visitors' => 3, 'ofStart' => 100, 'ofPrevious' => null],
                ['label' => 'Pages starting /products/', 'visitors' => 2, 'ofStart' => 66.7, 'ofPrevious' => 66.7],
                ['label' => 'Event purchase', 'visitors' => 1, 'ofStart' => 33.3, 'ofPrevious' => 50],
            ]);

        $this->actingAs($owner)->deleteJson("{$store}/{$funnel->id}")->assertSuccessful();
        $this->assertSame(0, AnalyticsFunnel::query()->count());
    }
}
