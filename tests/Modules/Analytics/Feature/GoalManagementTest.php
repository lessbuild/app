<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Goal;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

class GoalManagementTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_goal_form_sends_paused_state_and_preserves_matching_rule_history(): void
    {
        [$owner, $site] = $this->siteOwnedBy('Goals owner');

        $this->actingAs($owner)
            ->get(route('analytics.goals.create', $site))
            ->assertOk()
            ->assertSee('name="active" value="0"', false)
            ->assertSee('name="match_value"', false);

        $csrf = 'goal-form-token';
        $this->withSession(['_token' => $csrf])->actingAs($owner)
            ->post(route('analytics.goals.store', $site), [
                '_token' => $csrf,
                'name' => 'Demo requested',
                'kind' => 'path',
                'match_type' => 'exact',
                'match_value' => '/demo-thanks',
                'active' => '0',
            ])
            ->assertRedirect(route('analytics.goals.index', $site));

        $goal = Goal::query()->sole();
        $this->assertFalse($goal->active);
        $this->assertDatabaseHas('goal_versions', [
            'goal_id' => $goal->id,
            'kind' => 'path',
            'match_type' => 'exact',
            'match_value' => '/demo-thanks',
            'effective_to' => null,
        ]);

        $this->withSession(['_token' => $csrf])->actingAs($owner)
            ->put(route('analytics.goals.update', [$site, $goal]), [
                '_token' => $csrf,
                '_method' => 'PUT',
                'name' => 'Demo requested',
                'kind' => 'event',
                'match_type' => 'exact',
                'match_value' => 'demo_requested',
                'active' => '1',
            ])
            ->assertRedirect(route('analytics.goals.index', $site));

        $goal->refresh();
        $this->assertTrue($goal->active);
        $this->assertDatabaseCount('goal_versions', 2);
        $versions = $goal->versions()->orderBy('id')->get();
        $this->assertNotNull($versions[0]->effective_to);
        $this->assertSame('path', $versions[0]->kind);
        $this->assertSame('event', $versions[1]->kind);
        $this->assertSame('demo_requested', $versions[1]->match_value);
        $this->assertNull($versions[1]->effective_to);

        $this->withSession(['_token' => $csrf])->actingAs($owner)
            ->put(route('analytics.goals.update', [$site, $goal]), [
                '_token' => $csrf,
                '_method' => 'PUT',
                'name' => 'Demo requested',
                'kind' => 'event',
                'match_type' => 'exact',
                'match_value' => 'demo_requested',
                'active' => '0',
            ])
            ->assertRedirect(route('analytics.goals.index', $site));

        $this->assertFalse($goal->fresh()->active);
        $this->assertDatabaseCount('goal_versions', 2);

        $this->withSession(['_token' => $csrf])->actingAs($owner)
            ->delete(route('analytics.goals.destroy', [$site, $goal]), ['_token' => $csrf])
            ->assertRedirect(route('analytics.goals.index', $site));

        $this->assertDatabaseMissing('goals', ['id' => $goal->id]);
    }

    public function test_site_owner_cannot_edit_a_goal_belonging_to_another_site(): void
    {
        [$owner, $site] = $this->siteOwnedBy('Manager workspace');
        [, $otherSite] = $this->siteOwnedBy('Other workspace');
        $goal = $otherSite->goals()->create([
            'name' => 'Private goal',
            'kind' => 'path',
            'match_type' => 'exact',
            'match_value' => '/private',
            'active' => true,
        ]);

        $this->actingAs($owner)
            ->put(route('analytics.goals.update', [$site, $goal]), [
                'name' => 'Overwritten goal',
                'kind' => 'path',
                'match_type' => 'exact',
                'match_value' => '/overwritten',
                'active' => '1',
            ])
            ->assertNotFound();

        $this->assertSame('Private goal', $goal->fresh()->name);
    }

    /** @return array{0: User, 1: Site} */
    private function siteOwnedBy(string $workspaceName): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => $workspaceName]);
        $workspace->users()->attach($owner, ['role' => WorkspaceRole::Owner->value]);

        return [$owner, $workspace->sites()->create([
            'name' => $workspaceName.' site',
            'domains' => [str($workspaceName)->slug().'.example'],
            'timezone' => 'UTC',
            'verified_at' => now(),
        ])];
    }
}
