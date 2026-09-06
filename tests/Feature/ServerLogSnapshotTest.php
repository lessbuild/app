<?php

namespace Tests\Feature;

use App\Http\Livewire\ServerShow;
use App\Jobs\Server\RefreshServerLogJob;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Models\User;
use App\Services\ManagedSsh;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ServerLogSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_page_reads_a_persisted_snapshot_without_opening_ssh(): void
    {
        [$user, $server] = $this->server();
        $server->logSnapshots()->create([
            'type' => 'apt',
            'status' => ServerLogSnapshot::STATUS_READY,
            'log' => "Package installed\n<script>alert('xss')</script>",
            'refreshed_at' => now(),
        ]);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');
        $this->app->instance(Runner::class, $runner);

        $this->actingAs($user)->get(route('servers.show', $server))
            ->assertSuccessful()
            ->assertSeeText('Package installed')
            ->assertSee('server-show', false)
            ->assertDontSee("<script>alert('xss')</script>", false)
            ->assertDontSee('wire:poll.5s', false);
    }

    public function test_owner_can_queue_an_allowlisted_log_refresh(): void
    {
        Queue::fake();
        [$user, $server] = $this->server();

        Livewire::actingAs($user)
            ->test(ServerShow::class, ['server' => $server])
            ->set('log', 'caddy')
            ->call('refreshLogs')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('server_log_snapshots', [
            'server_id' => $server->id,
            'type' => 'caddy',
            'status' => ServerLogSnapshot::STATUS_QUEUED,
        ]);
        Queue::assertPushed(RefreshServerLogJob::class, fn (RefreshServerLogJob $job): bool => $job->serverId === $server->id && $job->type === 'caddy');
    }

    public function test_untrusted_log_type_is_normalized_before_a_job_is_dispatched(): void
    {
        Queue::fake();
        [$user, $server] = $this->server();

        Livewire::actingAs($user)
            ->test(ServerShow::class, ['server' => $server])
            ->set('log', '../../etc/shadow')
            ->call('refreshLogs')
            ->assertSet('log', 'apt');

        $this->assertDatabaseMissing('server_log_snapshots', ['type' => '../../etc/shadow']);
        Queue::assertPushed(RefreshServerLogJob::class, fn (RefreshServerLogJob $job): bool => $job->type === 'apt');
    }

    public function test_refresh_job_runs_a_fixed_command_and_bounds_the_saved_output(): void
    {
        [, $server] = $this->server();
        config(['lessbuild.server_log_max_characters' => 10]);
        $server->logSnapshots()->create([
            'type' => 'caddy',
            'status' => ServerLogSnapshot::STATUS_QUEUED,
        ]);
        $runner = $this->runner(
            successful: true,
            output: 'prefix-0123456789',
            command: 'journalctl -u caddy --no-pager -n 200',
        );

        (new RefreshServerLogJob($server->id, 'caddy'))->handle($runner);

        $snapshot = $server->logSnapshots()->sole();
        $this->assertSame(ServerLogSnapshot::STATUS_READY, $snapshot->status);
        $this->assertSame('0123456789', $snapshot->log);
        $this->assertNotNull($snapshot->refreshed_at);
    }

    public function test_failed_refresh_preserves_the_last_successful_snapshot(): void
    {
        [, $server] = $this->server();
        $server->logSnapshots()->create([
            'type' => 'mysql',
            'status' => ServerLogSnapshot::STATUS_QUEUED,
            'log' => 'Last known output',
            'refreshed_at' => now()->subMinute(),
        ]);
        $job = new RefreshServerLogJob($server->id, 'mysql');
        $runner = $this->runner(
            successful: false,
            output: '',
            command: 'tail -n 200 -- /var/log/mysql/error.log',
            error: 'Connection refused',
        );

        try {
            $job->handle($runner);
            $this->fail('The failed remote process should throw.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $snapshot = $server->logSnapshots()->sole();
        $this->assertSame(ServerLogSnapshot::STATUS_FAILED, $snapshot->status);
        $this->assertSame('Connection refused', $snapshot->error);
        $this->assertSame('Last known output', $snapshot->log);
    }

    public function test_page_only_polls_while_a_snapshot_is_pending(): void
    {
        [$user, $server] = $this->server();
        $snapshot = $server->logSnapshots()->create([
            'type' => 'apt',
            'status' => ServerLogSnapshot::STATUS_QUEUED,
        ]);

        $this->actingAs($user)->get(route('servers.show', $server))
            ->assertSee('wire:poll.5s', false);

        $snapshot->update(['status' => ServerLogSnapshot::STATUS_READY]);

        $this->actingAs($user)->get(route('servers.show', $server))
            ->assertDontSee('wire:poll.5s', false);
    }

    public function test_server_page_summarizes_allowlisted_snapshots_without_exposing_other_log_bodies(): void
    {
        [$user, $server] = $this->server();
        $server->logSnapshots()->create([
            'type' => 'apt',
            'status' => ServerLogSnapshot::STATUS_READY,
            'log' => 'Selected apt output',
            'refreshed_at' => '2026-09-03 10:00:00',
        ]);
        $server->logSnapshots()->create([
            'type' => 'caddy',
            'status' => ServerLogSnapshot::STATUS_QUEUED,
        ]);
        $server->logSnapshots()->create([
            'type' => 'mysql',
            'status' => ServerLogSnapshot::STATUS_REFRESHING,
        ]);
        $server->logSnapshots()->create([
            'type' => 'php',
            'status' => ServerLogSnapshot::STATUS_FAILED,
            'log' => 'non-selected-log-secret',
            'error' => 'non-selected-error-secret',
            'refreshed_at' => '2026-09-03 13:00:00',
        ]);

        $component = Livewire::actingAs($user)
            ->test(ServerShow::class, ['server' => $server])
            ->assertSee('Log snapshot overview')
            ->assertSee('Ready snapshots')
            ->assertSee('Queued snapshots')
            ->assertSee('Refreshing snapshots')
            ->assertSee('Failed snapshots')
            ->assertSee('Not collected')
            ->assertSee('Latest refresh')
            ->assertSee('Selected apt output')
            ->assertDontSee('non-selected-log-secret')
            ->assertDontSee('non-selected-error-secret');

        $metrics = $component->viewData('logMetrics');

        $this->assertSame(1, $metrics['ready']);
        $this->assertSame(1, $metrics['queued']);
        $this->assertSame(1, $metrics['refreshing']);
        $this->assertSame(1, $metrics['failed']);
        $this->assertSame(1, $metrics['missing']);
        $this->assertSame('2026-09-03 13:00:00', $metrics['latest_at']->format('Y-m-d H:i:s'));
    }

    public function test_snapshot_is_deleted_with_its_server(): void
    {
        [, $server] = $this->server();
        $snapshot = $server->logSnapshots()->create([
            'type' => 'apt',
            'status' => ServerLogSnapshot::STATUS_READY,
        ]);

        Server::withoutEvents(fn () => $server->delete());

        $this->assertDatabaseMissing('server_log_snapshots', ['id' => $snapshot->id]);
    }

    private function server(): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'cloud-secret',
            'description' => 'Cloud provider',
        ]);
        $server = $user->servers()->create([
            'provider_id' => $provider->id,
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        return [$user, $server];
    }

    private function runner(bool $successful, string $output, string $command, string $error = ''): Runner
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isSuccessful')->once()->andReturn($successful);
        $process->shouldReceive('getOutput')->zeroOrMoreTimes()->andReturn($output);
        $process->shouldReceive('getErrorOutput')->zeroOrMoreTimes()->andReturn($error);
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->with([$command])->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }
}
