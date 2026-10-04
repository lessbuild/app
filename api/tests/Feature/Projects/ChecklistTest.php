<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AnalyticsSite;
use App\Models\Build;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ChecklistTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The setup guide follows a project from connecting a provider to its first visit, with the step being worked on
     * and what was found; once everything is done the overview stops showing the checklist.
     */
    public function test_the_setup_guide_follows_a_project_from_provider_to_analytics(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $project = Project::factory()->for($account)->create(['name' => 'Storefront']);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $guide = "/api/app/projects/{$project->id}/setup";

        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}")->assertOk()->assertJsonCount(7, 'setup.steps');
        $steps = $this->actingAs($owner)->getJson($guide)->assertOk()->assertJsonPath('canChange', true);
        $this->assertSame(['provider', 'server', 'website', 'environment', 'deploy', 'monitor', 'analytics'], array_column($steps->json('setup.steps'), 'key'));
        $this->assertSame('todo', $this->step($steps, 'provider')['state']);
        $this->assertStringContainsString('/account/providers', (string) $this->step($steps, 'provider')['actionUrl']);
        $this->assertSame('add-provider', $this->step($steps, 'provider')['dialog']);
        $this->assertNull($this->step($steps, 'website')['dialog'], 'Turning Infrastructure on comes first.');

        foreach (['infrastructure', 'deploy', 'monitoring', 'analytics'] as $service) {
            $project->enabledServices()->forceCreate(['service' => $service]);
        }
        $provider = Provider::factory()->create(['account_id' => $account->id, 'name' => 'Main cloud']);
        $server = Server::factory()->provisioning(Server::STATUS_PROVISIONING, 2)->create(['provider_id' => $provider->id, 'name' => 'web-1']);
        $steps = $this->actingAs($owner)->getJson($guide);
        $this->assertSame(['done', 'Main cloud is connected.'], [$this->step($steps, 'provider')['state'], $this->step($steps, 'provider')['detail']]);
        $this->assertSame('working', $this->step($steps, 'server')['state']);
        $this->assertStringContainsString('web-1 is being set up', (string) $this->step($steps, 'server')['detail']);
        $this->assertSame(['create-website', 'add-site'], [$this->step($steps, 'website')['dialog'], $this->step($steps, 'analytics')['dialog']]);

        $server->forceFill(['provisioning_status' => Server::STATUS_ACTIVE])->save();
        $website = Website::factory()->create(['server_id' => $server->id, 'environment_id' => $production->id, 'name' => 'Shop']);
        $repository = Repository::factory()->create(['project_id' => $project->id, 'website_id' => $website->id, 'environment_id' => $production->id]);
        $steps = $this->actingAs($owner)->getJson($guide);
        $this->assertSame('Shop is live.', $this->step($steps, 'website')['detail']);
        $this->assertSame(['add-variable', (string) $production->id], [$this->step($steps, 'environment')['dialog'], $this->step($steps, 'environment')['dialogFor']]);
        $this->assertSame(4, count(array_filter($steps->json('setup.steps'), fn (array $step): bool => $step['state'] === 'done')));

        Build::factory()->succeeded()->create(['repository_id' => $repository->id]);
        Monitor::factory()->create(['environment_id' => $production->id]);
        $site = AnalyticsSite::factory()->create(['project_id' => $project->id, 'name' => 'storefront.example']);
        $steps = $this->actingAs($owner)->getJson($guide);
        $this->assertSame('working', $this->step($steps, 'analytics')['state']);
        $this->assertStringContainsString('Waiting for the first visit to storefront.example', (string) $this->step($steps, 'analytics')['detail']);

        $site->forceFill(['last_event_at' => now()])->save();
        $this->assertSame([], array_filter($this->actingAs($owner)->getJson($guide)->json('setup.steps'), fn (array $step): bool => $step['state'] !== 'done'));
    }

    /**
     * A variable added from the setup guide's dialog goes back to the guide; anywhere else is ignored.
     */
    public function test_a_variable_added_from_the_setup_guide_goes_back_to_it(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $project = Project::factory()->for($account)->create();
        $project->enabledServices()->forceCreate(['service' => 'deploy']);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $store = "/api/app/projects/{$project->id}/deploy/environments/{$production->id}/variables";
        $variable = ['value' => 'x', 'scope' => 'runtime', 'is_secret' => '1'];

        $this->actingAs($owner)->postJson($store, ['key' => 'FIRST', '_return' => "/projects/{$project->id}/setup"] + $variable)
            ->assertOk()->assertJsonPath('redirect', url("/projects/{$project->id}/setup"));
        $this->actingAs($owner)->postJson($store, ['key' => 'SECOND', '_return' => 'https://evil.example/'] + $variable)
            ->assertOk()->assertJsonPath('redirect', "/projects/{$project->id}/deploy/environments/{$production->id}?tab=variables");
    }

    /**
     * A project with only Analytics skips servers and deploys; unknown services are refused.
     */
    public function test_an_analytics_only_project_skips_servers_and_deploys(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $owner->forceFill(['current_account_id' => $account->id])->save();

        $this->actingAs($owner)->postJson('/api/app/projects', ['name' => 'Blog', 'services' => ['nope']])->assertJsonValidationErrors('services.0');
        $this->actingAs($owner)->postJson('/api/app/projects', ['name' => 'Blog', 'services' => ['analytics']])->assertCreated();
        $project = Project::query()->where('name', 'Blog')->sole();
        $this->assertSame(['analytics'], $project->enabledServices()->pluck('service')->all());

        $keys = array_column($this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/setup")->assertOk()->json('setup.steps'), 'key');
        $this->assertSame(['analytics'], $keys);
    }

    /**
     * The checklist can be hidden by those who manage the project; viewers never see it.
     */
    public function test_it_can_be_hidden_and_viewers_never_see_it(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $project = Project::factory()->for($account)->create();
        $viewer = User::factory()->create();
        $account->memberships()->forceCreate(['user_id' => $viewer->id, 'role' => AccountRole::Viewer]);

        $this->actingAs($viewer)->getJson("/api/app/projects/{$project->id}")->assertOk()->assertJsonPath('setup', null);
        $this->actingAs($viewer)->getJson("/api/app/projects/{$project->id}/setup")->assertOk()->assertJsonPath('canChange', false);
        $this->actingAs($viewer)->deleteJson("/api/app/projects/{$project->id}/checklist")->assertForbidden();

        $this->actingAs($owner)->deleteJson("/api/app/projects/{$project->id}/checklist")->assertOk();
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}")->assertOk()->assertJsonPath('setup', null);
    }

    /**
     * Find a step in the setup guide's answer by its key.
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @param  string  $key
     * @return array<string, mixed>
     */
    private function step(TestResponse $response, string $key): array
    {
        foreach ((array) $response->json('setup.steps') as $step) {
            if (is_array($step) && ($step['key'] ?? null) === $key) {
                return $step;
            }
        }
        $this->fail("No {$key} step.");
    }
}
