<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Goal;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

class WebsiteManagementTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_owner_can_create_update_and_remove_a_goal(): void
    {
        [$user, $site] = $this->site();
        $csrf = 'test-token';

        $this->withSession(['_token' => $csrf])->actingAs($user)->post(route('analytics.goals.store', $site), [
            '_token' => $csrf,
            'name' => 'Demo requested',
            'kind' => 'event',
            'match_type' => 'exact',
            'match_value' => 'demo_requested',
            'active' => '1',
        ])->assertRedirect(route('analytics.goals.index', $site));

        $goal = Goal::query()->sole();
        $this->assertSame('demo_requested', $goal->match_value);
        $this->assertDatabaseCount('goal_versions', 1);

        $this->withSession(['_token' => $csrf])->actingAs($user)->put(route('analytics.goals.update', [$site, $goal]), [
            '_token' => $csrf,
            'name' => 'Demo completed',
            'kind' => 'event',
            'match_type' => 'exact',
            'match_value' => 'demo_completed',
        ])->assertRedirect();
        $this->assertSame('demo_completed', $goal->fresh()->match_value);
        $this->assertDatabaseCount('goal_versions', 2);
        $this->assertDatabaseHas('goal_versions', ['goal_id' => $goal->id, 'match_value' => 'demo_requested']);

        $this->withSession(['_token' => $csrf])->actingAs($user)->delete(route('analytics.goals.destroy', [$site, $goal]), ['_token' => $csrf])->assertRedirect();
        $this->assertDatabaseMissing('goals', ['id' => $goal->id]);
    }

    public function test_site_settings_store_excluded_paths_and_pause_collection(): void
    {
        [$user, $site] = $this->site();
        $csrf = 'test-token';

        $this->withSession(['_token' => $csrf])->actingAs($user)->put(route('analytics.sites.settings.update', $site), [
            '_token' => $csrf,
            'name' => 'Updated site',
            'domains' => "example.com\nwww.example.com",
            'timezone' => 'UTC',
            'excluded_paths' => "/admin/*\n/account",
            'collection_enabled' => '1',
            'collection_paused' => '1',
        ])->assertRedirect();

        $site->refresh();
        $this->assertSame(['example.com', 'www.example.com'], $site->domains);
        $this->assertTrue($site->excludesPath('/admin/users'));
        $this->assertNotNull($site->collection_paused_at);
    }

    public function test_owner_deleting_a_site_removes_its_detail_rows(): void
    {
        [$user, $site] = $this->site();
        $site->goals()->create(['name' => 'Signup', 'kind' => 'path', 'match_type' => 'exact', 'match_value' => '/thank-you']);

        $csrf = 'test-token';
        $this->withSession(['_token' => $csrf, 'auth.password_confirmed_at' => now()->timestamp])->actingAs($user)->delete(route('analytics.sites.destroy', $site), ['_token' => $csrf])->assertRedirect(route('analytics.dashboard'));

        $this->assertDatabaseCount('goals', 0);
        $this->assertDatabaseCount('ingestion_batches', 0);
        $this->assertSoftDeleted('sites', ['id' => $site->id]);
    }

    /** @return array{0: User, 1: Site} */
    private function site(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Analytics workspace']);
        $workspace->users()->attach($user, ['role' => WorkspaceRole::Owner->value]);

        return [$user, $workspace->sites()->create([
            'name' => 'Example site',
            'domains' => ['example.com'],
            'timezone' => 'UTC',
            'verified_at' => now(),
        ])];
    }
}
