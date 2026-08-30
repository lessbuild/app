<?php

namespace Tests\Feature;

use App\Jobs\Web\AddWebsiteJob;
use App\Models\Build;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Repository\ActivateReleaseScript;
use App\Scripts\Repository\ArtisanCommandsScript;
use App\Scripts\Repository\PurgeOldReleasesScript;
use App\Scripts\Repository\VerifyDeploymentHealthScript;
use App\Services\RepositoryDeploymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_enable_a_normalized_health_check_path(): void
    {
        Queue::fake();
        [$owner, $server] = $this->infrastructure();

        $this->actingAs($owner)->post(route('websites.store'), [
            ...$this->websitePayload($server),
            'health_check_enabled' => '1',
            'health_check_path' => 'health/ready',
        ])->assertRedirect();

        $website = Website::query()->sole();
        $this->assertTrue($website->health_check_enabled);
        $this->assertSame('/health/ready', $website->health_check_path);
        Queue::assertPushed(AddWebsiteJob::class);

        $this->actingAs($owner)->get(route('websites.show', $website))
            ->assertSuccessful()
            ->assertSee('Deployment health check')
            ->assertSee('/health/ready');
        $this->actingAs($owner)->get(route('websites.edit', $website))
            ->assertSuccessful()
            ->assertSee('Verify website health after deployment')
            ->assertSee('health_check_enabled', false)
            ->assertSee('checked', false);
    }

    public function test_health_check_path_cannot_escape_the_website_origin(): void
    {
        Queue::fake();
        [$owner, $server] = $this->infrastructure();

        foreach (['//internal.example', '/health?target=internal', '/health#fragment', "/health\nnext"] as $path) {
            $this->actingAs($owner)->post(route('websites.store'), [
                ...$this->websitePayload($server),
                'health_check_enabled' => '1',
                'health_check_path' => $path,
            ])->assertSessionHasErrors('health_check_path');
        }

        $this->assertDatabaseCount('websites', 0);
        Queue::assertNothingPushed();
    }

    public function test_enabled_health_check_retries_and_atomically_restores_the_previous_release(): void
    {
        $build = $this->build(healthCheckEnabled: true, path: '/health/ready');
        $activate = (new ActivateReleaseScript)->script(4, $build);
        $health = (new VerifyDeploymentHealthScript)->script(7, $build);

        $this->assertStringContainsString('PREVIOUS_RELEASE_PATH="$(readlink -f -- "$CURRENT_PATH" || true)"', $activate);
        $this->assertStringContainsString('http://app.example.com/health/ready', $health);
        $this->assertStringContainsString('--connect-timeout 5 --max-time 15', $health);
        $this->assertStringContainsString('--retry 5 --retry-delay 2 --retry-all-errors', $health);
        $this->assertStringContainsString('Deployment health check failed', $health);
        $this->assertStringContainsString('DEPLOYMENT_FAILURE_MESSAGE="Deployment health check failed"', $health);
        $this->assertStringContainsString('false', $health);
        $this->assertShellSyntax($activate);
        $this->assertShellSyntax($health);
    }

    public function test_disabled_health_check_is_a_non_networking_progress_step(): void
    {
        $script = (new VerifyDeploymentHealthScript)->script(7, $this->build());

        $this->assertStringContainsString('# Health check disabled', $script);
        $this->assertStringNotContainsString('HEALTH_CHECK_URL=', $script);
        $this->assertStringNotContainsString('lessbuild-health-check', $script);
        $this->assertShellSyntax($script);
    }

    public function test_health_check_runs_after_application_setup_and_before_release_purge(): void
    {
        $scripts = app(RepositoryDeploymentPlan::class)->scripts();

        $this->assertLessThan(
            array_search(VerifyDeploymentHealthScript::class, $scripts, true),
            array_search(ArtisanCommandsScript::class, $scripts, true),
        );
        $this->assertLessThan(
            array_search(PurgeOldReleasesScript::class, $scripts, true),
            array_search(VerifyDeploymentHealthScript::class, $scripts, true),
        );
    }

    /** @return array{User, Server} */
    private function infrastructure(): array
    {
        $owner = User::factory()->create();
        $server = $owner->servers()->create([
            'name' => 'Production',
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'mysql-root-secret',
        ]);

        return [$owner, $server];
    }

    private function websitePayload(Server $server): array
    {
        return [
            'server_id' => $server->id,
            'name' => 'Application',
            'url' => 'app.example.com',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
        ];
    }

    private function build(bool $healthCheckEnabled = false, string $path = '/'): Build
    {
        [$owner, $server] = $this->infrastructure();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $website = $owner->websites()->create([
            ...$this->websitePayload($server),
            'database_password' => 'database-secret',
            'deployment_slug' => 'application',
            'health_check_enabled' => $healthCheckEnabled,
            'health_check_path' => $path,
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);

        return $repository->builds()->create(['status' => Build::STATUS_RUNNING]);
    }

    private function assertShellSyntax(string $script): void
    {
        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($script);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }
}
