<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\ManagedSsh;
use App\Services\RepositoryDeploymentPlan;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentWatchdogTest extends TestCase
{
    use RefreshDatabase;

    public function test_watchdog_stops_and_fails_a_stale_remote_deployment(): void
    {
        [$owner, $repository] = $this->repository();
        $build = $this->runningBuild($repository);
        $command = null;
        $this->app->instance(Runner::class, $this->runner(true, "Partial deployment output\n", $command));

        $this->assertSame(0, Artisan::call('lessbuild:deployments:watchdog', ['--minutes' => 10]));

        $build->refresh();
        $this->assertSame(Build::STATUS_FAILED, $build->status);
        $this->assertSame('Deployment timed out after 10 minutes without a heartbeat.', $build->failure_message);
        $this->assertNull($build->remote_process_id);
        $this->assertNull($build->remote_process_path);
        $this->assertNotNull($build->finished_at);
        $this->assertSame("Partial deployment output\n", $build->logs()->sole()->log);
        $this->assertStringContainsString("PID_FILE='/tmp/lessbuild-deployment-{$build->id}.pid'", $command);

        $this->actingAs($owner)->get(route('builds.show', $build))
            ->assertSuccessful()
            ->assertSee('Deployment timed out after 10 minutes without a heartbeat.');
    }

    public function test_recent_heartbeat_is_not_timed_out(): void
    {
        [, $repository] = $this->repository();
        $build = $this->runningBuild($repository, now()->subMinutes(9));
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');
        $this->app->instance(Runner::class, $runner);

        Artisan::call('lessbuild:deployments:watchdog', ['--minutes' => 10]);

        $this->assertSame(Build::STATUS_RUNNING, $build->fresh()->status);
    }

    public function test_signed_log_callback_refreshes_the_watchdog_heartbeat(): void
    {
        [, $repository] = $this->repository();
        $build = $this->runningBuild($repository);
        $staleHeartbeat = $build->last_heartbeat_at;

        $this->post(URL::signedRoute('callbacks.build.log', $build), [
            'log' => 'Deployment is still running',
        ])->assertNoContent();

        $this->assertTrue($build->fresh()->last_heartbeat_at->isAfter($staleHeartbeat));
    }

    public function test_stale_queued_build_is_failed_without_remote_access(): void
    {
        [, $repository] = $this->repository();
        $build = $repository->builds()->create([
            'status' => Build::STATUS_QUEUED,
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(11),
        ]);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');
        $this->app->instance(Runner::class, $runner);

        Artisan::call('lessbuild:deployments:watchdog', ['--minutes' => 10]);

        $this->assertSame(Build::STATUS_FAILED, $build->fresh()->status);
        $this->assertNotNull($build->fresh()->finished_at);
    }

    public function test_failed_remote_cancellation_stays_blocking_and_is_retried(): void
    {
        [$owner, $repository] = $this->repository();
        $build = $this->runningBuild($repository);
        $command = null;
        $this->app->instance(Runner::class, $this->runner(false, '', $command));

        Artisan::call('lessbuild:deployments:watchdog', ['--minutes' => 10]);

        $build->refresh();
        $this->assertSame(Build::STATUS_TIMING_OUT, $build->status);
        $this->assertSame(
            'Deployment timed out. Remote process cancellation will be retried automatically.',
            $build->failure_message,
        );
        $this->post(URL::signedRoute('callbacks.build.status', $build), [
            'status' => app(RepositoryDeploymentPlan::class)->finalStage(),
        ])->assertNoContent();
        $this->post(URL::signedRoute('callbacks.build.failed', $build), [
            'message' => 'Late remote failure',
        ])->assertNoContent();
        $this->post(URL::signedRoute('callbacks.build.log', $build), [
            'log' => 'Late remote log',
        ])->assertNoContent();
        $this->post(URL::signedRoute('callbacks.build.revision', $build), [
            'revision' => str_repeat('a', 40),
        ])->assertNoContent();
        $build->refresh();
        $this->assertSame(Build::STATUS_TIMING_OUT, $build->status);
        $this->assertNull($build->revision);
        $this->assertFalse($build->logs()->exists());
        $this->actingAs($owner)->post(route('repositories.deploy', $repository))
            ->assertSessionHas('info', 'A deployment is already in progress');
        $this->actingAs($owner)->get(route('builds.show', $build))
            ->assertSuccessful()
            ->assertSee('Timing Out')
            ->assertSee('safely stopping its remote process')
            ->assertSee('wire:poll.5s', false);

        $this->travel(2)->minutes();
        $this->app->instance(Runner::class, $this->runner(true, '', $command));
        Artisan::call('lessbuild:deployments:watchdog', ['--minutes' => 10]);
        $this->assertSame(Build::STATUS_FAILED, $build->fresh()->status);
    }

    private function runningBuild(Repository $repository, mixed $heartbeat = null): Build
    {
        return $repository->builds()->create([
            'status' => Build::STATUS_RUNNING,
            'remote_process_id' => 4242,
            'remote_process_path' => '/tmp/lessbuild-deployment-1.sh',
            'started_at' => now()->subMinutes(12),
            'last_heartbeat_at' => $heartbeat ?? now()->subMinutes(11),
        ]);
    }

    private function runner(bool $successful, string $output, ?string &$command): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturn($successful);
        if ($successful) {
            $process->shouldReceive('getOutput')->andReturn($output);
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

    /** @return array{User, Repository} */
    private function repository(): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'source-secret',
            'description' => 'Source provider',
        ]);
        $server = $owner->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $owner->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
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

        return [$owner, $repository];
    }
}
