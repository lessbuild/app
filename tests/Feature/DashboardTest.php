<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_shows_only_the_authenticated_users_activity(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $repository = $this->createResources($user, 'My Application');
        $this->createResources($otherUser, 'Someone Else Application');
        Build::create(['repository_id' => $repository->id, 'built_at' => now()]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response
            ->assertSuccessful()
            ->assertSee('My Application')
            ->assertSee('Recent websites')
            ->assertSee('Recent builds')
            ->assertSee('Recent activity')
            ->assertSee('No active failures')
            ->assertSee(route('activity.index'))
            ->assertSee(route('builds.show', $repository->builds()->sole()))
            ->assertDontSee('Someone Else Application');
    }

    public function test_empty_dashboard_offers_useful_next_actions(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response
            ->assertSuccessful()
            ->assertSee('No websites yet')
            ->assertSee('No builds yet')
            ->assertSee('No activity yet')
            ->assertSee(route('servers.create'))
            ->assertSee(route('websites.create'));
    }

    public function test_dashboard_surfaces_only_the_owners_current_failures(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $repository = $this->createResources($user, 'Broken Application');
        $otherRepository = $this->createResources($otherUser, 'Private Failure');

        $repository->website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_UNHEALTHY,
        ]);
        $repository->website->server->update(['provisioning_status' => Server::STATUS_FAILED]);
        $failedBuild = Build::create([
            'repository_id' => $repository->id,
            'status' => Build::STATUS_FAILED,
            'finished_at' => now(),
        ]);
        $otherRepository->website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_UNHEALTHY,
        ]);
        $otherRepository->website->server->update(['provisioning_status' => Server::STATUS_FAILED]);
        Build::create([
            'repository_id' => $otherRepository->id,
            'status' => Build::STATUS_FAILED,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('Needs attention')
            ->assertSee('3 active issues')
            ->assertSee('Health check failing')
            ->assertSee('Provisioning failed')
            ->assertSee('Latest deployment failed')
            ->assertSee(route('websites.show', $repository->website))
            ->assertSee(route('servers.show', $repository->website->server))
            ->assertSee(route('builds.show', $failedBuild))
            ->assertDontSee('Private Failure');
    }

    public function test_a_successful_latest_deployment_and_recovered_resources_clear_attention(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $repository = $this->createResources($user, 'Recovered Application');
        Build::create([
            'repository_id' => $repository->id,
            'status' => Build::STATUS_FAILED,
        ]);
        Build::create([
            'repository_id' => $repository->id,
            'status' => Build::STATUS_SUCCEEDED,
        ]);
        $repository->website->update([
            'health_check_enabled' => true,
            'health_status' => Website::HEALTH_HEALTHY,
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository->website->server->update(['provisioning_status' => Server::STATUS_ACTIVE]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('No active failures')
            ->assertSee('No unhealthy websites, provisioning failures, or failed latest deployments.')
            ->assertDontSee('Needs attention');
    }

    private function createResources(User $user, string $name)
    {
        $provider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => 'github',
            'token' => 'secret',
            'description' => 'Git provider',
        ]);
        $server = $user->servers()->create([
            'name' => "$name Server",
            'provider_id' => $provider->id,
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => $name,
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => str($name)->slug().'.test',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => "$name Repository",
            'url' => 'github.com/example/project.git',
            'description' => 'Repository',
        ]);
    }
}
