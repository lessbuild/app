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

        $base = "/projects/{$project->id}/analytics/funnels";
        $this->actingAs($owner)->get($base)->assertOk()->assertSee('No funnels yet');
        $store = "/projects/{$project->id}/analytics/sites/{$site->id}/funnels";
        $this->actingAs($owner)->post($store, ['name' => 'One step', 'steps' => [['kind' => 'pageview', 'match' => 'exact', 'value' => '/pricing']]])->assertSessionHasErrors('steps');
        $this->actingAs($owner)->post($store, ['name' => 'Bad', 'steps' => [['kind' => 'pageview', 'value' => 'pricing'], ['kind' => 'pageview', 'value' => '/x']]])->assertSessionHasErrors('steps');
        $this->actingAs($owner)->post($store, ['name' => 'Checkout', 'steps' => [
            ['kind' => 'pageview', 'match' => 'exact', 'value' => '/pricing'],
            ['kind' => 'pageview', 'match' => 'prefix', 'value' => '/products/'],
            ['kind' => 'event', 'match' => 'exact', 'value' => 'purchase'],
            ['kind' => 'pageview', 'match' => 'exact', 'value' => ''],
        ]])->assertRedirect("{$base}?site={$site->id}");
        $funnel = AnalyticsFunnel::query()->sole();
        $this->assertCount(3, $funnel->steps);

        $this->actingAs($owner)->get($base)->assertOk()
            ->assertSeeInOrder(['Page /pricing', '3', '100%', 'Pages starting /products/', '2', '66.7%', '66.7% carried on', 'Event purchase', '1', '33.3%', '50% carried on']);

        $this->actingAs($owner)->delete("{$store}/{$funnel->id}")->assertRedirect();
        $this->assertSame(0, AnalyticsFunnel::query()->count());
    }
}
