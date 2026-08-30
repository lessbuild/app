<?php

namespace Tests\Feature;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ManagedSsh;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_stop_remote_process_group_and_preserve_partial_log(): void
    {
        [$owner, $build] = $this->build();
        $processPath = $build->remote_process_path;
        $command = null;
        $runner = $this->runner(successful: true, output: "Installing dependencies\n", command: $command);
        $this->app->instance(Runner::class, $runner);

        $this->actingAs($owner)->post(route('builds.cancel', $build))
            ->assertRedirect()
            ->assertSessionHas('success', 'Deployment canceled.');

        $build->refresh();
        $this->assertSame(Build::STATUS_CANCELED, $build->status);
        $this->assertNull($build->remote_process_id);
        $this->assertNull($build->remote_process_path);
        $this->assertNotNull($build->finished_at);
        $this->assertSame("Installing dependencies\n", $build->logs()->sole()->log);
        $this->assertStringContainsString('sudo kill -TERM -- -4242', $command);
        $this->assertStringContainsString('sudo kill -KILL -- -4242', $command);
        $this->assertStringContainsString('/proc/4242/cmdline', $command);
        $this->assertStringContainsString($processPath, $command);
        $this->assertStringContainsString("lessbuild-deployment-{$build->id}.log", $command);
        $this->assertStringNotContainsString("\0", $command);
        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($command);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());

        $this->actingAs($owner)->get(route('builds.show', $build))
            ->assertSuccessful()
            ->assertSee('This deployment was canceled')
            ->assertDontSee('Cancel deployment');

        $this->actingAs($owner)->get(route('repositories.show', $build->repository))
            ->assertSuccessful()
            ->assertSee('Deployment canceled')
            ->assertDontSee('wire:poll.5s', false);
    }

    public function test_failed_remote_cancellation_preserves_running_state(): void
    {
        [$owner, $build] = $this->build();
        $command = null;
        $this->app->instance(Runner::class, $this->runner(successful: false, output: '', command: $command));

        $this->actingAs($owner)->post(route('builds.cancel', $build))
            ->assertRedirect()
            ->assertSessionHas('error', 'The deployment could not be canceled. Please try again.');

        $build->refresh();
        $this->assertSame(Build::STATUS_RUNNING, $build->status);
        $this->assertSame(4242, $build->remote_process_id);
        $this->assertSame('/tmp/application-repository-abcd1234.sh', $build->remote_process_path);
        $this->assertNull($build->finished_at);
    }

    public function test_other_users_and_non_running_builds_cannot_cancel(): void
    {
        [$owner, $build] = $this->build();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('builds.cancel', $build))
            ->assertForbidden();

        $build->update([
            'status' => Build::STATUS_SUCCEEDED,
            'remote_process_id' => null,
            'remote_process_path' => null,
            'finished_at' => now(),
        ]);
        $this->actingAs($owner)->post(route('builds.cancel', $build))
            ->assertSessionHas('info', 'This deployment is no longer running.');
    }

    public function test_late_callbacks_and_worker_failures_cannot_regress_a_canceled_build(): void
    {
        [, $build] = $this->build();
        $build->update([
            'status' => Build::STATUS_CANCELED,
            'remote_process_id' => null,
            'remote_process_path' => null,
            'finished_at' => now(),
        ]);

        $this->post(URL::signedRoute('callbacks.build.status', $build), ['status' => 7])
            ->assertNoContent();
        $this->post(URL::signedRoute('callbacks.build.failed', $build), [
            'exit_code' => 143,
            'message' => 'Remote deployment script failed',
        ])->assertNoContent();
        (new PublishRepositoryJob($build))->failed(new RuntimeException('Late worker failure'));

        $this->assertSame(Build::STATUS_CANCELED, $build->fresh()->status);
    }

    public function test_worker_skips_a_build_that_was_canceled_before_it_started(): void
    {
        [, $build] = $this->build();
        $build->update([
            'status' => Build::STATUS_CANCELED,
            'remote_process_id' => null,
            'remote_process_path' => null,
        ]);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');

        (new PublishRepositoryJob($build))->handle($runner);

        $this->assertSame(Build::STATUS_CANCELED, $build->fresh()->status);
    }

    public function test_worker_persists_the_remote_process_identifier(): void
    {
        [, $build] = $this->build();
        $build->update([
            'status' => Build::STATUS_QUEUED,
            'remote_process_id' => null,
            'started_at' => null,
        ]);

        (new PublishRepositoryJob($build))->handle($this->publishingRunner($build));

        $build->refresh();
        $this->assertSame(Build::STATUS_RUNNING, $build->status);
        $this->assertSame(9876, $build->remote_process_id);
        $this->assertMatchesRegularExpression('#^/tmp/application-repository-[a-z0-9]{8}\.sh$#', $build->remote_process_path);
        $this->assertNotNull($build->started_at);
    }

    public function test_worker_does_not_regress_a_build_completed_during_remote_launch(): void
    {
        [, $build] = $this->build();
        $build->update([
            'status' => Build::STATUS_QUEUED,
            'remote_process_id' => null,
            'remote_process_path' => null,
            'started_at' => null,
        ]);

        (new PublishRepositoryJob($build))->handle($this->publishingRunner($build, completeDuringLaunch: true));

        $build->refresh();
        $this->assertSame(Build::STATUS_SUCCEEDED, $build->status);
        $this->assertNull($build->remote_process_id);
        $this->assertNull($build->remote_process_path);
    }

    private function runner(bool $successful, string $output, ?string &$command): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturn($successful);
        if ($successful) {
            $process->shouldReceive('getOutput')->twice()->andReturn($output);
        }

        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')
            ->once()
            ->withArgs(function (string $value) use (&$command): bool {
                $command = $value;

                return true;
            })
            ->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }

    private function publishingRunner(Build $build, bool $completeDuringLaunch = false): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->twice()->andReturnTrue();
        $process->shouldReceive('getOutput')->once()->andReturn("9876\n");
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('upload')->once()->andReturn($process);
        $ssh->shouldReceive('execute')->once()->andReturnUsing(function () use ($build, $completeDuringLaunch, $process) {
            if ($completeDuringLaunch) {
                $build->update([
                    'status' => Build::STATUS_SUCCEEDED,
                    'built_at' => now(),
                    'finished_at' => now(),
                ]);
            }

            return $process;
        });
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
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
            'status' => Build::STATUS_RUNNING,
            'remote_process_id' => 4242,
            'remote_process_path' => '/tmp/application-repository-abcd1234.sh',
            'started_at' => now()->subMinute(),
        ]);

        return [$user, $build];
    }
}
