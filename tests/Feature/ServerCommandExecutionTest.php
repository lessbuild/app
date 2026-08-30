<?php

namespace Tests\Feature;

use App\Http\Livewire\ServerCommand;
use App\Jobs\Server\RunServerCommandJob;
use App\Models\Server;
use App\Models\ServerCommandExecution;
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

class ServerCommandExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_queues_an_encrypted_command_without_secrets_in_the_job(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();
        $command = 'systemctl status caddy';

        Livewire::actingAs($user)
            ->test(ServerCommand::class, ['model' => $server])
            ->call('open')
            ->set('command', $command)
            ->call('run')
            ->assertHasNoErrors()
            ->assertSet('command', '');

        $execution = $server->commandExecutions()->sole();
        $this->assertSame(ServerCommandExecution::STATUS_QUEUED, $execution->status);
        $this->assertSame($command, $execution->command);
        $this->assertNotSame(
            $command,
            ServerCommandExecution::query()->toBase()->find($execution->id)->command,
        );
        $this->assertArrayNotHasKey('command', $execution->toArray());
        $this->assertArrayNotHasKey('output', $execution->toArray());

        Queue::assertPushed(RunServerCommandJob::class, function (RunServerCommandJob $job) use ($command, $execution, $server): bool {
            $serialized = serialize($job);
            $this->assertSame($execution->id, $job->executionId);
            $this->assertStringNotContainsString($command, $serialized);
            $this->assertStringNotContainsString($server->ssh_private_key, $serialized);

            return true;
        });
    }

    public function test_only_one_command_can_be_active_and_invalid_commands_are_rejected(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();
        $component = Livewire::actingAs($user)
            ->test(ServerCommand::class, ['model' => $server])
            ->set('command', 'uptime')
            ->call('run')
            ->assertHasNoErrors();

        $component->set('command', 'whoami')
            ->call('run')
            ->assertHasErrors(['command']);
        $component->set('command', "printf 'bad\0command'")
            ->call('run')
            ->assertHasErrors(['command' => 'Commands cannot contain null bytes.']);
        $component->set('command', '   ')
            ->call('run')
            ->assertHasErrors(['command' => 'required']);

        Queue::assertPushedTimes(RunServerCommandJob::class, 1);
        $this->assertDatabaseCount('server_command_executions', 1);
    }

    public function test_inactive_servers_and_other_users_cannot_queue_commands(): void
    {
        Queue::fake();
        [$user, $server] = $this->resources();
        $server->update(['provisioning_status' => Server::STATUS_FAILED]);

        Livewire::actingAs($user)
            ->test(ServerCommand::class, ['model' => $server])
            ->set('command', 'uptime')
            ->call('run')
            ->assertHasErrors(['command']);

        Livewire::actingAs(User::factory()->create())
            ->test(ServerCommand::class, ['model' => $server])
            ->assertForbidden();
    }

    public function test_job_runs_the_exact_command_and_bounds_encrypted_output(): void
    {
        [, $server] = $this->resources();
        config(['lessbuild.server_command_output_max_characters' => 10]);
        $execution = $this->execution($server, 'journalctl -u caddy');
        $runner = $this->runner(
            successful: true,
            output: 'prefix-0123456789',
            error: '',
            exitCode: 0,
            expectedCommand: 'journalctl -u caddy',
        );

        $job = new RunServerCommandJob($execution->id);
        $job->handle($runner);
        $job->handle($runner);
        $job->failed(new RuntimeException('Late failure'));

        $execution->refresh();
        $this->assertSame(ServerCommandExecution::STATUS_SUCCEEDED, $execution->status);
        $this->assertSame('0123456789', $execution->output);
        $this->assertSame(0, $execution->exit_code);
        $this->assertNotNull($execution->started_at);
        $this->assertNotNull($execution->finished_at);
        $this->assertNotSame(
            $execution->output,
            ServerCommandExecution::query()->toBase()->find($execution->id)->output,
        );
    }

    public function test_nonzero_exit_and_transport_exceptions_are_persisted_without_retrying_commands(): void
    {
        [, $server] = $this->resources();
        $failed = $this->execution($server, 'false');
        $job = new RunServerCommandJob($failed->id);
        $this->assertSame(1, $job->tries);
        $this->assertTrue($job->failOnTimeout);
        $this->assertGreaterThan((int) config('lessbuild.ssh_command_timeout'), $job->timeout);
        $job->handle($this->runner(
            successful: false,
            output: '',
            error: 'Permission denied',
            exitCode: 126,
            expectedCommand: 'false',
        ));

        $failed->refresh();
        $this->assertSame(ServerCommandExecution::STATUS_FAILED, $failed->status);
        $this->assertSame('Permission denied', $failed->output);
        $this->assertSame(126, $failed->exit_code);

        $transport = $this->execution($server, 'hostname');
        $transportJob = new RunServerCommandJob($transport->id);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andThrow(new RuntimeException('Connection timed out'));

        try {
            $transportJob->handle($runner);
            $this->fail('The transport exception should escape to the queue worker.');
        } catch (RuntimeException $exception) {
            $transportJob->failed($exception);
        }

        $transport->refresh();
        $this->assertSame(ServerCommandExecution::STATUS_FAILED, $transport->status);
        $this->assertSame('Unable to execute command: Connection timed out', $transport->output);
        $this->assertNotNull($transport->finished_at);
    }

    public function test_job_skips_remote_execution_when_server_is_no_longer_active(): void
    {
        [, $server] = $this->resources();
        $execution = $this->execution($server, 'uptime');
        $server->update(['provisioning_status' => Server::STATUS_FAILED]);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');

        (new RunServerCommandJob($execution->id))->handle($runner);

        $execution->refresh();
        $this->assertSame(ServerCommandExecution::STATUS_FAILED, $execution->status);
        $this->assertSame('The server is no longer active.', $execution->output);
    }

    public function test_dialog_polls_active_history_and_escapes_commands_and_output(): void
    {
        [$user, $server] = $this->resources();
        $execution = $this->execution($server, '<script>alert(1)</script>');

        Livewire::actingAs($user)
            ->test(ServerCommand::class, ['model' => $server])
            ->call('open')
            ->assertSee('wire:poll.2s', false)
            ->assertSeeText('<script>alert(1)</script>')
            ->assertDontSee('<script>alert(1)</script>', false);

        $execution->update([
            'status' => ServerCommandExecution::STATUS_SUCCEEDED,
            'output' => '<img src=x onerror=alert(1)>',
            'exit_code' => 0,
            'finished_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ServerCommand::class, ['model' => $server])
            ->call('open')
            ->assertDontSee('wire:poll.2s', false)
            ->assertSeeText('<img src=x onerror=alert(1)>')
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_command_history_is_deleted_with_the_server(): void
    {
        [, $server] = $this->resources();
        $execution = $this->execution($server, 'uptime');

        Server::withoutEvents(fn () => $server->delete());

        $this->assertDatabaseMissing('server_command_executions', ['id' => $execution->id]);
    }

    private function resources(): array
    {
        $user = User::factory()->create();
        $server = $user->servers()->create([
            'name' => 'Production',
            'public_ip' => '192.0.2.10',
            'ssh_private_key' => 'private-key',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        return [$user, $server];
    }

    private function execution(Server $server, string $command): ServerCommandExecution
    {
        return $server->commandExecutions()->create([
            'user_id' => $server->user_id,
            'command' => $command,
            'status' => ServerCommandExecution::STATUS_QUEUED,
        ]);
    }

    private function runner(
        bool $successful,
        string $output,
        string $error,
        int $exitCode,
        string $expectedCommand,
    ): Runner {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getOutput')->once()->andReturn($output);
        $process->shouldReceive('getErrorOutput')->once()->andReturn($error);
        $process->shouldReceive('isSuccessful')->once()->andReturn($successful);
        $process->shouldReceive('getExitCode')->once()->andReturn($exitCode);
        $ssh = Mockery::mock(ManagedSsh::class);
        $ssh->shouldReceive('execute')->once()->with($expectedCommand)->andReturn($process);
        $runner = Mockery::mock(Runner::class);
        $runner->shouldReceive('server')->once()->andReturnSelf();
        $runner->shouldReceive('create')->once()->andReturn($ssh);

        return $runner;
    }
}
