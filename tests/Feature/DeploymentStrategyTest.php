<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Repository\ConfigureProcessesScript;
use App\Scripts\Repository\ValidateCandidateScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function test_environment_release_strategy_is_validated_and_saved(): void
    {
        [$user, $build] = $this->build(['deployment_strategy' => 'blue_green']);
        $environment = $build->environment;

        $this->actingAs($user)->patch(route('environments.deployment-controls.update', $environment), [
            'deployment_locked' => 0,
            'deployment_window_enabled' => 0,
            'deployment_strategy' => 'canary',
            'rolling_pause_seconds' => 5,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('canary', $environment->fresh()->deployment_strategy);
        $this->assertSame(5, $environment->fresh()->rolling_pause_seconds);
    }

    public function test_canary_candidate_receives_loopback_http_before_activation(): void
    {
        [, $build] = $this->build(['deployment_strategy' => 'canary']);
        $script = (new ValidateCandidateScript)->script(8, $build);

        $this->assertStringContainsString('php -S "127.0.0.1:$CANARY_PORT"', $script);
        $this->assertStringContainsString('--header "Host: $CANARY_HOST"', $script);
        $this->assertStringContainsString('Canary candidate health validation failed', $script);
        $this->assertShellSyntax($script);
    }

    public function test_rolling_strategy_restarts_workers_one_at_a_time_and_removes_obsolete_units_afterward(): void
    {
        [, $build] = $this->build([
            'deployment_strategy' => 'rolling', 'rolling_pause_seconds' => 5,
            'minimum_replicas' => 1, 'maximum_replicas' => 2, 'desired_replicas' => 2,
        ], [['name' => 'queue', 'type' => 'worker', 'command' => 'php artisan queue:work', 'replicas' => 2]]);
        $script = (new ConfigureProcessesScript)->script(10, $build);

        $this->assertStringContainsString('OLD_MANIFEST="$PROCESS_DIR/units.previous"', $script);
        $this->assertStringContainsString("systemctl restart \"\$unit\"\nsleep 5", $script);
        $this->assertStringContainsString('done < "$OLD_MANIFEST"', $script);
        $this->assertShellSyntax($script);
    }

    /** @param array<string, mixed> $runtime */
    private function build(array $runtime, array $processes = []): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create(['name' => 'GitHub', 'provider' => Provider::TYPE_GITHUB, 'token' => 'secret', 'description' => 'Source']);
        $server = $user->servers()->create(['name' => 'server', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $user->websites()->create(['server_id' => $server->id, 'name' => 'App', 'description' => 'App', 'environment' => '', 'url' => 'app.example.com', 'deployment_slug' => 'app', 'provisioning_status' => Website::STATUS_ACTIVE, 'health_check_enabled' => true, 'health_check_path' => '/health']);
        $repository = $user->repositories()->create(['provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'App', 'url' => 'github.com/example/app.git', 'branch' => 'main', 'description' => 'App']);
        $project = $user->currentOrganization->projects()->create(['created_by' => $user->id, 'name' => 'App', 'slug' => 'app', 'preset' => 'custom']);
        $environment = $project->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main']);
        $build = $repository->builds()->create([
            'environment_id' => $environment->id,
            'status' => Build::STATUS_RUNNING,
            'environment_payload' => ['runtime' => $runtime, 'processes' => $processes],
        ]);

        return [$user, $build];
    }

    private function assertShellSyntax(string $script): void
    {
        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($script);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }
}
