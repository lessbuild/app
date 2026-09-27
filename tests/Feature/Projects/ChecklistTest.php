<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Domain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_checklist_ticks_off_steps_and_goes_away_when_complete(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $project = Project::factory()->for($account)->create(['name' => 'Storefront']);
        $url = "/projects/{$project->id}";
        $heading = __('Get :project going', ['project' => 'Storefront']);

        $this->actingAs($owner)->get($url)->assertOk()->assertSee($heading)->assertSee(__(':done of :total done', ['done' => 1, 'total' => 4]));

        $project->enabledServices()->forceCreate(['service' => 'deploy']);
        (new Domain)->forceFill(['project_id' => $project->id, 'hostname' => 'example.com', 'verification_token' => 'x'])->save();
        $this->actingAs($owner)->get($url)->assertSee(__(':done of :total done', ['done' => 3, 'total' => 4]))->assertSee(route('account.members'), false);

        $account->memberships()->forceCreate(['user_id' => User::factory()->create()->id, 'role' => AccountRole::Member]);
        $this->actingAs($owner)->get($url)->assertOk()->assertDontSee($heading);
    }

    public function test_it_can_be_hidden_and_viewers_never_see_it(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $project = Project::factory()->for($account)->create(['name' => 'Storefront']);
        $heading = __('Get :project going', ['project' => 'Storefront']);
        $viewer = User::factory()->create();
        $account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);

        $this->actingAs($viewer)->get("/projects/{$project->id}")->assertOk()->assertDontSee($heading);
        $this->actingAs($viewer)->delete("/projects/{$project->id}/checklist")->assertForbidden();

        $this->actingAs($owner)->delete("/projects/{$project->id}/checklist")->assertRedirect("/projects/{$project->id}");
        $this->actingAs($owner)->get("/projects/{$project->id}")->assertOk()->assertDontSee($heading);
    }
}
