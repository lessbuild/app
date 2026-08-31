<?php

namespace Tests\Feature;

use App\Http\Livewire\ServerCommand;
use App\Jobs\Server\RunServerCommandJob;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Services\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ServerCommandLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_cancel_a_queued_command_and_run_another(): void
    {
        Queue::fake();
        [$owner, $server] = $this->resources();
        $component = Livewire::actingAs($owner)
            ->test(ServerCommand::class, ['model' => $server])
            ->call('open')
            ->set('command', 'systemctl restart caddy')
            ->call('run');
        $execution = $server->commandExecutions()->sole();

        $component
            ->assertSee('View full history')
            ->assertSee('Cancel')
            ->call('cancel', $execution->id)
            ->assertHasNoErrors()
            ->assertSee('canceled');

        $execution->refresh();
        $this->assertSame(ServerCommandExecution::STATUS_CANCELED, $execution->status);
        $this->assertNotNull($execution->finished_at);
        $this->assertDatabaseHas('events', [
            'user_id' => $owner->id,
            'category' => 'command',
            'event' => 'Server command canceled.',
        ]);

        $runner = Mockery::mock(Runner::class);
        $runner->shouldNotReceive('server');
        (new RunServerCommandJob($execution->id))->handle($runner);

        $component
            ->set('command', 'uptime')
            ->call('run')
            ->assertHasNoErrors();
        $this->assertDatabaseCount('server_command_executions', 2);
        Queue::assertPushedTimes(RunServerCommandJob::class, 2);
    }

    public function test_cancellation_loses_safely_when_the_worker_has_started(): void
    {
        [$owner, $server] = $this->resources();
        $execution = $this->execution($server, ServerCommandExecution::STATUS_RUNNING, 'apt-get update');

        $this->actingAs($owner)
            ->post(route('servers.commands.cancel', [
                'server' => $server,
                'execution' => $execution,
            ]))
            ->assertSessionHas('info', 'This command is no longer queued and cannot be canceled.');

        $this->assertSame(ServerCommandExecution::STATUS_RUNNING, $execution->fresh()->status);

        Livewire::actingAs($owner)
            ->test(ServerCommand::class, ['model' => $server])
            ->call('cancel', $execution->id)
            ->assertHasErrors(['cancel']);
    }

    public function test_command_history_is_owner_scoped_filterable_and_paginated(): void
    {
        [$owner, $server] = $this->resources();
        foreach (range(1, 26) as $position) {
            $this->execution(
                $server,
                ServerCommandExecution::STATUS_SUCCEEDED,
                "successful-command-{$position}",
            );
        }
        $this->execution($server, ServerCommandExecution::STATUS_FAILED, 'failed-command');
        [$intruder] = $this->resources();

        $this->actingAs($owner)
            ->get(route('servers.commands.index', [
                'server' => $server,
                'status' => ServerCommandExecution::STATUS_SUCCEEDED,
            ]))
            ->assertSuccessful()
            ->assertViewHas('executions', fn ($executions): bool => $executions->count() === 25 && $executions->lastPage() === 2)
            ->assertSee('successful-command-26')
            ->assertDontSee('failed-command');

        $this->actingAs($intruder)
            ->get(route('servers.commands.index', $server))
            ->assertForbidden();
    }

    public function test_owner_can_download_exact_command_output_but_not_foreign_or_missing_output(): void
    {
        [$owner, $server] = $this->resources();
        $output = "Service restarted\r\n\x1b[32mOK\x1b[0m\n";
        $execution = $this->execution($server, ServerCommandExecution::STATUS_SUCCEEDED, 'systemctl restart caddy');
        $execution->update([
            'output' => $output,
            'exit_code' => 0,
            'finished_at' => now(),
        ]);
        $withoutOutput = $this->execution($server, ServerCommandExecution::STATUS_CANCELED, 'hostname');
        $intruder = User::factory()->create();

        $this->actingAs($owner)->get(route('servers.commands.index', $server))
            ->assertSuccessful()
            ->assertSee('Download output')
            ->assertSee(route('servers.commands.output', [
                'server' => $server,
                'execution' => $execution,
            ]));

        $response = $this->get(route('servers.commands.output', [
            'server' => $server,
            'execution' => $execution,
        ]));
        $response
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('Content-Disposition', "attachment; filename=lessbuild-server-{$server->id}-command-{$execution->id}.log")
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($output, $response->getContent());

        $this->get(route('servers.commands.output', [
            'server' => $server,
            'execution' => $withoutOutput,
        ]))->assertNotFound();
        $this->actingAs($intruder)->get(route('servers.commands.output', [
            'server' => $server,
            'execution' => $execution,
        ]))->assertForbidden();
    }

    public function test_nested_command_actions_reject_an_execution_from_another_server(): void
    {
        [$owner, $server] = $this->resources();
        $otherServer = $owner->servers()->create([
            'name' => 'Other production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $foreignExecution = $this->execution($otherServer, ServerCommandExecution::STATUS_QUEUED, 'uptime');

        $this->actingAs($owner)->post(route('servers.commands.cancel', [
            'server' => $server,
            'execution' => $foreignExecution,
        ]))->assertNotFound();
        $this->get(route('servers.commands.output', [
            'server' => $server,
            'execution' => $foreignExecution,
        ]))->assertNotFound();
    }

    private function resources(): array
    {
        $owner = User::factory()->create();
        $server = $owner->servers()->create([
            'name' => 'Production',
            'public_ip' => '192.0.2.10',
            'ssh_private_key' => 'private-key',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        return [$owner, $server];
    }

    private function execution(Server $server, string $status, string $command): ServerCommandExecution
    {
        return $server->commandExecutions()->create([
            'user_id' => $server->user_id,
            'command' => $command,
            'status' => $status,
        ]);
    }
}
