<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_setup_guide_follows_a_project_from_provider_to_analytics(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $project = Project::factory()->for($account)->create(['name' => 'Storefront']);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $guide = "/projects/{$project->id}/setup";
        $heading = __('Get :project going', ['project' => 'Storefront']);

        $this->actingAs($owner)->get("/projects/{$project->id}")->assertOk()->assertSee($heading)->assertSee(__(':done of :total done', ['done' => 0, 'total' => 7]))->assertSee(route('projects.setup', $project));
        $this->actingAs($owner)->get($guide)->assertOk()->assertSee('Step 1 of 7')->assertSee('Connect a cloud provider')->assertSee('aria-current="step"', false)->assertSee(route('account.providers'))
            ->assertSee('Turn on Infrastructure')->assertSee(route('projects.services.show', [$project, 'infrastructure']));

        foreach (['infrastructure', 'deploy', 'monitoring', 'analytics'] as $service) {
            $project->enabledServices()->forceCreate(['service' => $service]);
        }
        $provider = \App\Models\Provider::factory()->create(['account_id' => $account->id, 'name' => 'Main cloud']);
        $server = \App\Models\Server::factory()->provisioning(\App\Models\Server::STATUS_PROVISIONING, 2)->create(['provider_id' => $provider->id, 'name' => 'web-1']);
        $this->actingAs($owner)->get($guide)->assertSee('Main cloud is connected.')->assertSee('web-1 is being set up', false)->assertSee('Watch it live')->assertSee('http-equiv="refresh"', false);

        $server->forceFill(['provisioning_status' => \App\Models\Server::STATUS_ACTIVE])->save();
        $website = \App\Models\Website::factory()->create(['server_id' => $server->id, 'environment_id' => $production->id, 'name' => 'Shop']);
        $repository = \App\Models\Repository::factory()->create(['project_id' => $project->id, 'website_id' => $website->id, 'environment_id' => $production->id]);
        $this->actingAs($owner)->get($guide)->assertSee('Shop is live.')->assertSee('Step 5 of 7')->assertSee(route('deploy.repositories.show', [$project, $repository->id]))
            ->assertSee(__(':done of :total done', ['done' => 4, 'total' => 7]));

        \App\Models\Build::factory()->succeeded()->create(['repository_id' => $repository->id]);
        \App\Models\Monitor::factory()->create(['environment_id' => $production->id]);
        $site = \App\Models\AnalyticsSite::factory()->create(['project_id' => $project->id, 'name' => 'storefront.example']);
        $this->actingAs($owner)->get($guide)->assertSee('Waiting for the first visit to storefront.example')->assertSee('Step 7 of 7');

        $site->forceFill(['last_event_at' => now()])->save();
        $this->actingAs($owner)->get($guide)->assertSee('Storefront is set up.')->assertDontSee('http-equiv="refresh"', false);
        $this->actingAs($owner)->get("/projects/{$project->id}")->assertOk()->assertDontSee($heading);
    }

    public function test_new_projects_open_on_their_setup_guide(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $owner->forceFill(['current_account_id' => $account->id])->save();

        $this->actingAs($owner)->post('/projects', ['name' => 'Blog'])->assertRedirect(route('projects.setup', Project::query()->where('name', 'Blog')->sole()));
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
