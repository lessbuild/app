<?php

namespace Tests\Feature;

use App\Actions\Repository\PublishRepositoryAction;
use App\Http\Livewire\BuildDeploymentStatus;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ManagedSsh;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_callback_records_and_replaces_a_bounded_deployment_log(): void
    {
        [, $build] = $this->build();
        $callback = URL::signedRoute('callbacks.build.log', $build);

        $this->post(route('callbacks.build.log', $build), ['log' => 'unsigned'])
            ->assertForbidden();

        $this->post($callback, ['log' => "Installing dependencies\nDone"])
            ->assertNoContent();
        $this->post($callback, ['log' => 'Deployment complete'])
            ->assertNoContent();

        $this->assertDatabaseCount('logs', 1);
        $this->assertSame('Deployment complete', $build->logs()->sole()->log);
        $this->assertSame('deployment', $build->logs()->sole()->type);

        config(['lessbuild.deployment_log_max_characters' => 10]);
        $this->post($callback, ['log' => str_repeat('x', 11)])
            ->assertSessionHasErrors('log');
        $this->assertSame('Deployment complete', $build->logs()->sole()->log);
    }

    public function test_build_details_and_logs_are_only_visible_to_the_owner(): void
    {
        [$owner, $build] = $this->build();
        $intruder = User::factory()->create();
        $build->logs()->create([
            'type' => 'deployment',
            'log' => "Composer packages installed\nApplication deployed\n<script>alert('xss')</script>",
        ]);

        $this->actingAs($owner)->get(route('builds.show', $build))
            ->assertSuccessful()
            ->assertSee('Deployment log')
            ->assertSeeText('Composer packages installed')
            ->assertSeeText('Application deployed')
            ->assertDontSee("<script>alert('xss')</script>", false);

        $this->actingAs($intruder)->get(route('builds.show', $build))
            ->assertForbidden();

        Livewire::actingAs($intruder)
            ->test(BuildDeploymentStatus::class, ['build' => $build])
            ->assertForbidden();
    }

    public function test_running_build_details_poll_for_live_status_and_escaped_logs(): void
    {
        [$owner, $build] = $this->build();
        $build->update([
            'status' => Build::STATUS_RUNNING,
            'remote_process_id' => 4321,
            'remote_process_path' => '/tmp/application-repository-abcd1234.sh',
            'finished_at' => null,
        ]);
        $build->logs()->create([
            'type' => Build::DEPLOYMENT_LOG_TYPE,
            'log' => "Installing dependencies\n<script>alert('live')</script>",
        ]);

        $this->actingAs($owner)->get(route('builds.show', $build))
            ->assertSuccessful()
            ->assertSee('wire:poll.5s', false)
            ->assertSee('Running')
            ->assertSee('Cancel deployment')
            ->assertSeeText('Installing dependencies')
            ->assertDontSee("<script>alert('live')</script>", false);

        $component = Livewire::actingAs($owner)
            ->test(BuildDeploymentStatus::class, ['build' => $build])
            ->assertSee('Running')
            ->assertSeeHtml('wire:poll.5s');

        $build->update([
            'status' => Build::STATUS_SUCCEEDED,
            'remote_process_id' => null,
            'remote_process_path' => null,
            'finished_at' => now(),
        ]);

        $this->actingAs($owner)->get(route('builds.show', $build))
            ->assertSuccessful()
            ->assertDontSee('wire:poll.5s', false)
            ->assertDontSee('Cancel deployment');

        $component
            ->call('$refresh')
            ->assertSee('Succeeded')
            ->assertDontSeeHtml('wire:poll.5s')
            ->assertDontSee('Cancel deployment');
    }

    public function test_late_log_callback_cannot_replace_a_canceled_deployment_log(): void
    {
        [, $build] = $this->build();
        $build->update(['status' => Build::STATUS_CANCELED]);
        $build->logs()->create([
            'type' => Build::DEPLOYMENT_LOG_TYPE,
            'log' => 'Final output captured while canceling',
        ]);

        $this->post(URL::signedRoute('callbacks.build.log', $build), [
            'log' => 'Late in-flight snapshot',
        ])->assertNoContent();

        $this->assertSame('Final output captured while canceling', $build->logs()->sole()->log);
    }

    public function test_remote_deployment_script_captures_bounded_output_for_success_and_failure(): void
    {
        [, $build] = $this->build();
        $script = null;
        $success = Mockery::mock(Process::class);
        $success->shouldReceive('isSuccessful')->andReturnTrue();
        $success->shouldReceive('getOutput')->andReturn('4321');
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('upload')
            ->once()
            ->withArgs(function (string $sourcePath) use (&$script): bool {
                $script = file_get_contents($sourcePath);

                return true;
            })
            ->andReturn($success);
        $ssh->shouldReceive('execute')->once()->andReturn($success);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        $remoteProcess = (new PublishRepositoryAction($build, $runner))->handle();

        $this->assertSame(4321, $remoteProcess['id']);
        $this->assertMatchesRegularExpression('#^/tmp/application-repository-[a-z0-9]{8}\.sh$#', $remoteProcess['path']);
        $this->assertNotNull($script);
        $this->assertStringContainsString('exec > "$LOG_FILE" 2>&1', $script);
        $this->assertStringContainsString('tail -c 262144', $script);
        $this->assertStringContainsString('--data-urlencode "log@$LOG_UPLOAD_FILE"', $script);
        $this->assertStringContainsString('--data-urlencode "message=$DEPLOYMENT_FAILURE_MESSAGE"', $script);
        $this->assertStringContainsString('trap deployment_failed ERR', $script);
        $this->assertStringContainsString('while sleep 5; do', $script);
        $this->assertStringContainsString('stream_deployment_log &', $script);
        $this->assertStringContainsString('stop_deployment_log_stream', $script);
        $this->assertStringContainsString('restore_previous_release()', $script);
        $this->assertStringContainsString('ln -sfn -- "$PREVIOUS_RELEASE_PATH" "$rollback_link"', $script);
        $this->assertStringContainsString('mv -Tf -- "$rollback_link" "$DEPLOY_ROOT/current"', $script);
        $this->assertStringContainsString("stop_deployment_log_stream\n    restore_previous_release\n    upload_deployment_log", $script);
        $this->assertSame(4, substr_count($script, 'upload_deployment_log'));

        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($script);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    }

    private function build(): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'secret',
            'description' => 'Source provider',
        ]);
        $server = $user->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $user->repositories()->create([
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ]);
        $build = $repository->builds()->create([
            'status' => Build::STATUS_SUCCEEDED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        return [$user, $build];
    }
}
