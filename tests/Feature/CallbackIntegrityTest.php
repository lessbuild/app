<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ProvisioningCallbackUrl;
use App\Services\RepositoryDeploymentPlan;
use App\Services\ServerProvisioningPlan;
use App\Services\WebsiteProvisioningPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CallbackIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_callback_urls_expire(): void
    {
        config(['lessbuild.server_callback_ttl_minutes' => 1]);
        [, $server] = $this->infrastructure();
        $url = ProvisioningCallbackUrl::serverStatus($server);

        $this->assertStringContainsString('expires=', $url);
        $this->travel(2)->minutes();

        $this->post($url, ['status' => 1])->assertForbidden();
        $this->assertSame(0, $server->fresh()->setup_stage);
    }

    public function test_legacy_servers_without_attempt_tokens_still_accept_signed_callbacks(): void
    {
        [, $server] = $this->infrastructure();
        $server->update(['provisioning_token' => null]);

        $this->post(URL::signedRoute('callbacks.server', $server), ['status' => 1])
            ->assertSuccessful();

        $this->assertSame(1, $server->fresh()->setup_stage);
    }

    public function test_stale_callbacks_cannot_regress_terminal_server_or_website_states(): void
    {
        [, $server, $website] = $this->infrastructure(withWebsite: true);
        $server->update([
            'provisioning_status' => Server::STATUS_ACTIVE,
            'provisioned_at' => now(),
        ]);
        $website->update([
            'provisioning_status' => Website::STATUS_ACTIVE,
            'provisioned_at' => now(),
        ]);

        $this->post(ProvisioningCallbackUrl::serverFailure($server), [
            'message' => 'Late server failure',
        ])->assertNoContent();
        $this->post(ProvisioningCallbackUrl::websiteFailure($website), [
            'message' => 'Late website failure',
        ])->assertNoContent();

        $this->assertSame(Server::STATUS_ACTIVE, $server->fresh()->provisioning_status);
        $this->assertNull($server->fresh()->provisioning_error);
        $this->assertSame(Website::STATUS_ACTIVE, $website->fresh()->provisioning_status);
        $this->assertNull($website->fresh()->provisioning_error);

        $server->update(['provisioning_status' => Server::STATUS_FAILED]);
        $finalStage = app(ServerProvisioningPlan::class)->finalStage($server);
        $this->post(ProvisioningCallbackUrl::serverStatus($server), ['status' => $finalStage])
            ->assertNoContent();
        $this->assertSame(Server::STATUS_FAILED, $server->fresh()->provisioning_status);
    }

    public function test_build_specific_callbacks_cannot_complete_a_newer_deployment(): void
    {
        [$user, $server, $website, $provider] = $this->infrastructure(withWebsite: true);
        $repository = $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);
        $oldBuild = $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);
        $newBuild = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);
        $repository->update(['setup_stage' => 0]);

        $finalStage = app(RepositoryDeploymentPlan::class)->finalStage();
        $this->post(URL::signedRoute('callbacks.build.status', $oldBuild), ['status' => $finalStage])
            ->assertNoContent();

        $this->assertSame(0, $repository->fresh()->setup_stage);
        $this->assertSame(Build::STATUS_RUNNING, $newBuild->fresh()->status);

        $this->post(ProvisioningCallbackUrl::buildStatus($newBuild), ['status' => $finalStage])
            ->assertNoContent();
        $this->assertSame($finalStage, $repository->fresh()->setup_stage);
        $this->assertSame(Build::STATUS_SUCCEEDED, $newBuild->fresh()->status);
        $this->assertNotNull($newBuild->fresh()->finished_at);
        $this->assertSame(Server::STATUS_PROVISIONING, $server->provisioning_status);
    }

    public function test_completion_callbacks_follow_the_current_plan_lengths(): void
    {
        [$user, , $website, $provider] = $this->infrastructure(withWebsite: true);
        $websitePlan = \Mockery::mock(WebsiteProvisioningPlan::class);
        $websitePlan->shouldReceive('finalStage')->once()->andReturn(4);
        $this->app->instance(WebsiteProvisioningPlan::class, $websitePlan);

        $this->post(ProvisioningCallbackUrl::websiteStatus($website), ['status' => 4])
            ->assertSuccessful();
        $this->assertSame(Website::STATUS_ACTIVE, $website->fresh()->provisioning_status);

        $repository = $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Plan-driven application',
            'url' => 'github.com/example/plan-driven.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);
        $build = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);
        $repositoryPlan = \Mockery::mock(RepositoryDeploymentPlan::class);
        $repositoryPlan->shouldReceive('finalStage')->once()->andReturn(8);
        $repositoryPlan->shouldReceive('activationStage')->once()->andReturn(7);
        $this->app->instance(RepositoryDeploymentPlan::class, $repositoryPlan);

        $this->post(ProvisioningCallbackUrl::buildStatus($build), ['status' => 8])
            ->assertNoContent();
        $this->assertSame(Build::STATUS_SUCCEEDED, $build->fresh()->status);
    }

    private function infrastructure(bool $withWebsite = false): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'Provider',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'secret',
            'description' => 'Provider',
        ]);
        $server = $user->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_PROVISIONING,
        ]);
        $website = null;

        if ($withWebsite) {
            $website = $user->websites()->create([
                'server_id' => $server->id,
                'name' => 'Application',
                'description' => 'Website',
                'environment' => 'APP_ENV=production',
                'url' => 'app.example.com',
                'provisioning_status' => Website::STATUS_PROVISIONING,
            ]);
        }

        return [$user, $server, $website, $provider];
    }
}
