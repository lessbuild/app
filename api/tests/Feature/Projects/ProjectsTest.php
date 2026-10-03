<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->account = Account::factory()->withMember($this->owner)->create(['name' => 'Acme']);
    }

    /**
     * The first project: an empty dashboard, creating a project with a service, its overview and checklist, and
     * turning services on and off.
     */
    public function test_the_first_project_journey(): void
    {
        $this->actingAs($this->owner)->getJson('/api/app/dashboard')->assertOk()->assertJsonPath('projects', [])->assertJsonPath('canCreate', true);
        $this->actingAs($this->owner)->getJson('/api/app/projects/new')->assertOk()->assertJsonPath('services.0.key', 'deploy');

        $created = $this->actingAs($this->owner)->postJson('/api/app/projects', ['name' => 'Storefront', 'description' => 'Our shop', 'services' => ['analytics']])->assertCreated();
        $project = Project::query()->sole();
        $this->assertSame($this->account->id, $project->account_id);
        $created->assertJsonPath('redirect', "/projects/{$project->id}/setup")->assertJsonPath('message', __('Project created. Here’s what to set up next.'));
        $this->assertTrue($project->hasService('analytics'));

        $overview = $this->actingAs($this->owner)->getJson("/api/app/projects/{$project->id}")->assertOk();
        $overview->assertJsonPath('overview.project.name', 'Storefront')->assertJsonPath('overview.project.accountName', 'Acme')->assertJsonPath('overview.canManage', true);
        $this->assertNotNull($overview->json('setup.steps'));
        $this->assertSame('production', $overview->json('overview.environments.0.kind'));

        $this->actingAs($this->owner)->postJson("/api/app/projects/{$project->id}/services/monitoring")->assertOk()->assertJsonPath('redirect', "/projects/{$project->id}/services/monitoring");
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$project->id}/services/monitoring")->assertOk()->assertJsonPath('redirect', "/projects/{$project->id}/monitoring");
        $this->actingAs($this->owner)->getJson('/api/app/dashboard')->assertOk()->assertJsonPath('projects.0.name', 'Storefront');

        $this->actingAs($this->owner)->deleteJson("/api/app/projects/{$project->id}/services/monitoring")->assertOk()->assertJsonPath('redirect', "/projects/{$project->id}");
        $this->assertFalse($project->hasService('monitoring'));
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$project->id}/services/monitoring")->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('service.name', 'Monitoring');

        $this->actingAs($this->owner)->deleteJson("/api/app/projects/{$project->id}/checklist")->assertOk();
        $this->actingAs($this->owner)->getJson("/api/app/projects/{$project->id}")->assertJsonPath('setup', null);
    }

    /**
     * Viewers see projects but can't create or change them.
     */
    public function test_viewers_see_projects_but_cannot_create_or_change_them(): void
    {
        $project = Project::factory()->for($this->account)->create(['name' => 'Storefront']);
        $viewer = $this->join(AccountRole::Viewer)->user;

        $this->actingAs($viewer)->getJson('/api/app/dashboard')->assertOk()->assertJsonPath('projects.0.name', 'Storefront')->assertJsonPath('canCreate', false);
        $this->actingAs($viewer)->getJson('/api/app/projects/new')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/app/projects', ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($viewer)->getJson("/api/app/projects/{$project->id}")->assertOk()->assertJsonPath('overview.canManage', false)->assertJsonPath('setup', null);
        $this->actingAs($viewer)->postJson("/api/app/projects/{$project->id}/services/deploy")->assertForbidden();
        $this->actingAs($viewer)->getJson("/api/app/projects/{$project->id}/settings")->assertForbidden();
    }

    /**
     * Outsiders get a 404 (so project IDs don't leak); members of the project's account who are looking at another of
     * their accounts switch to it.
     */
    public function test_outsiders_get_a_404_and_members_of_another_account_switch_to_it(): void
    {
        $project = Project::factory()->for($this->account)->create();
        $this->actingAs(User::factory()->create())->getJson("/api/app/projects/{$project->id}")->assertNotFound();
        $this->actingAs($this->owner)->getJson('/api/app/projects/01J0000000000000000000000X')->assertNotFound();

        $member = $this->join(AccountRole::Member)->user;
        $home = Account::factory()->withMember($member)->create();
        $member->forceFill(['current_account_id' => $home->id])->save();

        $this->actingAs($member)->getJson("/api/app/projects/{$project->id}")->assertOk();
        $this->assertSame($this->account->id, $member->refresh()->current_account_id);
    }

    /**
     * Settings: rename, add and remove environments (production stays unique), and delete after confirming the name.
     */
    public function test_project_settings_rename_manage_environments_and_delete(): void
    {
        $project = Project::factory()->for($this->account)->create(['name' => 'Old name']);
        $base = "/api/app/projects/{$project->id}";

        $this->actingAs($this->owner)->getJson("{$base}/settings")->assertOk()->assertJsonPath('kinds.0.value', 'staging');
        $this->actingAs($this->owner)->putJson($base, ['name' => 'New name', 'description' => ''])->assertOk()->assertJsonPath('message', __('Project saved.'));
        $this->assertSame('New name', $project->refresh()->name);

        $this->actingAs($this->owner)->postJson("{$base}/environments", ['name' => 'Staging', 'kind' => 'staging'])->assertOk();
        $this->actingAs($this->owner)->postJson("{$base}/environments", ['name' => 'staging', 'kind' => 'staging'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($this->owner)->postJson("{$base}/environments", ['name' => 'Prod', 'kind' => 'production'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $staging = $project->environments()->where('slug', 'staging')->sole();
        $this->actingAs($this->owner)->getJson("{$base}/settings")->assertJsonFragment(['name' => 'Staging', 'kind' => 'staging']);
        $this->actingAs($this->owner)->deleteJson("{$base}/environments/{$staging->id}")->assertOk();
        $this->assertSame(1, $project->environments()->count());

        $this->actingAs($this->owner)->deleteJson($base, ['confirm_name' => 'New name'])->assertStatus(423);
        $confirmed = ['auth.password_confirmed_at' => PHP_INT_MAX];
        $this->actingAs($this->owner)->withSession($confirmed)->deleteJson($base, ['confirm_name' => 'Old name'])->assertUnprocessable()->assertJsonValidationErrors('confirm_name');
        $this->actingAs($this->owner)->withSession($confirmed)->deleteJson($base, ['confirm_name' => 'New name'])->assertOk()->assertJsonPath('redirect', '/dashboard');
        $this->assertNull($project->fresh());
    }

    /**
     * Join the test account in a role.
     */
    private function join(AccountRole $role): Membership
    {
        $user = User::factory()->create();
        $membership = $this->account->memberships()->forceCreate(['user_id' => $user->id, 'role' => $role]);
        $user->forceFill(['current_account_id' => $this->account->id])->save();

        return $membership;
    }
}
