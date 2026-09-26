<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectPagesTest extends TestCase
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

    public function test_the_first_project_journey(): void
    {
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertSee(__('Create your first project'))->assertSee(route('projects.create'), false);

        $this->actingAs($this->owner)->post('/projects', ['name' => 'Storefront', 'description' => 'Our shop'])->assertRedirect();
        $project = Project::query()->sole();
        $this->assertSame($this->account->id, $project->account_id);

        $this->actingAs($this->owner)->get("/projects/{$project->id}")
            ->assertOk()
            ->assertSee('Storefront')
            ->assertSee(__('Turn on the services this project needs'))
            ->assertSee(__('Turn on :service', ['service' => 'Monitoring']));

        $this->actingAs($this->owner)->post("/projects/{$project->id}/services/monitoring")->assertRedirect("/projects/{$project->id}/services/monitoring");
        $this->actingAs($this->owner)->get("/projects/{$project->id}/services/monitoring")->assertOk()->assertSee(__(':service is on for :project', ['service' => 'Monitoring', 'project' => 'Storefront']));
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertSee('Storefront')->assertSee('Monitoring');

        $this->actingAs($this->owner)->delete("/projects/{$project->id}/services/monitoring")->assertRedirect("/projects/{$project->id}");
        $this->assertFalse($project->hasService('monitoring'));
    }

    public function test_viewers_see_projects_but_cannot_create_or_change_them(): void
    {
        $project = Project::factory()->for($this->account)->create(['name' => 'Storefront']);
        $viewer = $this->join(AccountRole::Viewer)->user;

        $this->actingAs($viewer)->get('/dashboard')->assertOk()->assertSee('Storefront')->assertDontSee(route('projects.create'), false);
        $this->actingAs($viewer)->get('/projects/create')->assertForbidden();
        $this->actingAs($viewer)->get("/projects/{$project->id}")->assertOk()->assertDontSee(__('Turn on :service', ['service' => 'Deploy']));
        $this->actingAs($viewer)->post("/projects/{$project->id}/services/deploy")->assertForbidden();
        $this->actingAs($viewer)->get("/projects/{$project->id}/settings")->assertForbidden();
    }

    public function test_outsiders_get_a_404_and_members_of_another_account_switch_to_it(): void
    {
        $project = Project::factory()->for($this->account)->create();
        $this->actingAs(User::factory()->create())->get("/projects/{$project->id}")->assertNotFound();
        $this->actingAs($this->owner)->get('/projects/01J0000000000000000000000X')->assertNotFound();

        $member = $this->join(AccountRole::Member)->user;
        $home = Account::factory()->withMember($member)->create();
        $member->forceFill(['current_account_id' => $home->id])->save();

        $this->actingAs($member)->get("/projects/{$project->id}")->assertOk();
        $this->assertSame($this->account->id, $member->refresh()->current_account_id);
    }

    public function test_members_limited_to_some_services_only_see_those(): void
    {
        $project = Project::factory()->for($this->account)->withServices(['deploy', 'analytics'])->create();
        $membership = $this->join(AccountRole::Member);

        $this->actingAs($this->owner)->put("/account/members/{$membership->id}/services", ['access' => 'some', 'services' => ['analytics']])->assertRedirect('/account/members');
        $this->assertSame(['analytics'], $membership->refresh()->service_access);
        $this->actingAs($this->owner)->get('/account/members')->assertSee('Services: Analytics');

        $member = $membership->user;
        $this->actingAs($member)->get("/projects/{$project->id}")
            ->assertOk()
            ->assertSee(route('projects.services.show', [$project->id, 'analytics']), false)
            ->assertSee(__('You don’t have access to :service in this account.', ['service' => 'Deploy']));
        $this->actingAs($member)->get("/projects/{$project->id}/services/deploy")->assertForbidden();
        $this->actingAs($member)->get("/projects/{$project->id}/services/analytics")->assertOk();

        $this->actingAs($this->owner)->put("/account/members/{$membership->id}/services", ['access' => 'all'])->assertRedirect('/account/members');
        $this->assertNull($membership->refresh()->service_access);
    }

    public function test_project_settings_rename_manage_environments_and_delete(): void
    {
        $project = Project::factory()->for($this->account)->create(['name' => 'Old name']);
        $base = "/projects/{$project->id}";

        $this->actingAs($this->owner)->put("{$base}/settings", ['name' => 'New name', 'description' => ''])->assertRedirect("{$base}/settings");
        $this->assertSame('New name', $project->refresh()->name);

        $this->actingAs($this->owner)->post("{$base}/environments", ['name' => 'Staging', 'kind' => 'staging'])->assertRedirect("{$base}/settings");
        $this->actingAs($this->owner)->post("{$base}/environments", ['name' => 'staging', 'kind' => 'staging'])->assertSessionHasErrorsIn('environment', 'name');
        $this->actingAs($this->owner)->post("{$base}/environments", ['name' => 'Prod', 'kind' => 'production'])->assertSessionHasErrorsIn('environment', 'kind');
        $staging = $project->environments()->where('slug', 'staging')->sole();
        $this->actingAs($this->owner)->get("{$base}/settings")->assertOk()->assertSee('Staging')->assertSee(route('projects.environments.destroy', [$project, $staging->id]), false);
        $this->actingAs($this->owner)->delete("{$base}/environments/{$staging->id}")->assertRedirect("{$base}/settings");
        $this->assertSame(1, $project->environments()->count());

        $confirmed = ['auth.password_confirmed_at' => PHP_INT_MAX];
        $this->actingAs($this->owner)->withSession($confirmed)->delete($base, ['confirm_name' => 'Old name'])->assertSessionHasErrorsIn('deleteProject', 'confirm_name');
        $this->actingAs($this->owner)->withSession($confirmed)->delete($base, ['confirm_name' => 'New name'])->assertRedirect('/dashboard');
        $this->assertNull($project->fresh());
    }

    private function join(AccountRole $role): Membership
    {
        $user = User::factory()->create();
        $membership = $this->account->memberships()->forceCreate(['user_id' => $user->id, 'role' => $role]);
        $user->forceFill(['current_account_id' => $this->account->id])->save();

        return $membership;
    }
}
