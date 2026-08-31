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

    public function test_owner_can_rerun_a_terminal_command_with_encrypted_lineage(): void
    {
        Queue::fake();
        [$owner, $server] = $this->resources();
        $command = 'systemctl restart caddy';
        $source = $this->execution($server, ServerCommandExecution::STATUS_FAILED, $command);
        $source->update([
            'output' => 'Demo failure output',
            'exit_code' => 1,
            'finished_at' => now()->subMinute(),
        ]);

        $this->actingAs($owner)
            ->get(route('servers.commands.index', $server))
            ->assertSuccessful()
            ->assertSee('Run again')
            ->assertSee(route('servers.commands.rerun', [
                'server' => $server,
                'execution' => $source,
            ]));
        $this->post(route('servers.commands.rerun', [
            'server' => $server,
            'execution' => $source,
        ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $rerun = $server->commandExecutions()->where('id', '!=', $source->id)->sole();
        $this->assertSame(ServerCommandExecution::STATUS_QUEUED, $rerun->status);
        $this->assertSame($command, $rerun->command);
        $this->assertSame($source->id, $rerun->rerun_from_execution_id);
        $this->assertTrue($rerun->rerunFrom->is($source));
        $this->assertNotSame(
            $command,
            ServerCommandExecution::query()->toBase()->find($rerun->id)->command,
        );
        $this->assertSame(ServerCommandExecution::STATUS_FAILED, $source->fresh()->status);
        $this->assertSame('Demo failure output', $source->fresh()->output);
        Queue::assertPushed(RunServerCommandJob::class, function (RunServerCommandJob $job) use ($command, $rerun): bool {
            $this->assertSame($rerun->id, $job->executionId);
            $this->assertStringNotContainsString($command, serialize($job));

            return true;
        });

        $this->get(route('servers.commands.index', $server))
            ->assertSuccessful()
            ->assertSee("Rerun of command #{$source->id}");
    }

    public function test_rerun_respects_active_command_and_server_lifecycle_guards(): void
    {
        Queue::fake();
        [$owner, $server] = $this->resources();
        $source = $this->execution($server, ServerCommandExecution::STATUS_SUCCEEDED, 'uptime');
        $active = $this->execution($server, ServerCommandExecution::STATUS_RUNNING, 'whoami');

        $this->actingAs($owner)->post(route('servers.commands.rerun', [
            'server' => $server,
            'execution' => $source,
        ]))
            ->assertSessionHasErrors('command');
        $this->assertDatabaseCount('server_command_executions', 2);
        Queue::assertNothingPushed();

        $active->update([
            'status' => ServerCommandExecution::STATUS_FAILED,
            'finished_at' => now(),
        ]);
        $server->update(['provisioning_status' => Server::STATUS_FAILED]);
        $this->post(route('servers.commands.rerun', [
            'server' => $server,
            'execution' => $source,
        ]))
            ->assertSessionHasErrors('command');
        $this->assertDatabaseCount('server_command_executions', 2);
        Queue::assertNothingPushed();
    }

    public function test_livewire_history_can_rerun_a_terminal_command(): void
    {
        Queue::fake();
        [$owner, $server] = $this->resources();
        $source = $this->execution($server, ServerCommandExecution::STATUS_CANCELED, 'hostname');

        Livewire::actingAs($owner)
            ->test(ServerCommand::class, ['model' => $server])
            ->call('open')
            ->assertSee('Run again')
            ->call('rerun', $source->id)
            ->assertHasNoErrors()
            ->assertSee("Rerun of command #{$source->id}");

        $this->assertDatabaseHas('server_command_executions', [
            'server_id' => $server->id,
            'status' => ServerCommandExecution::STATUS_QUEUED,
            'rerun_from_execution_id' => $source->id,
        ]);
        Queue::assertPushedTimes(RunServerCommandJob::class, 1);
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

    public function test_filtered_command_export_is_owner_scoped_spreadsheet_safe_and_excludes_output(): void
    {
        [$owner, $server] = $this->resources();
        $source = $this->execution(
            $server,
            ServerCommandExecution::STATUS_FAILED,
            '=HYPERLINK("https://example.test")',
        );
        $source->update([
            'output' => 'sensitive-output-must-use-separate-download',
            'exit_code' => 17,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
        ]);
        $rerun = $this->execution($server, ServerCommandExecution::STATUS_FAILED, $source->command);
        $rerun->update(['rerun_from_execution_id' => $source->id]);
        $this->execution($server, ServerCommandExecution::STATUS_SUCCEEDED, 'filtered-out-command');
        [$otherOwner, $otherServer] = $this->resources();
        $this->execution($otherServer, ServerCommandExecution::STATUS_FAILED, 'foreign-private-command');

        $response = $this->actingAs($owner)->get(route('servers.commands.export', [
            $server,
            'status' => ServerCommandExecution::STATUS_FAILED,
        ]));

        $response
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString(
            "attachment; filename=lessbuild-server-{$server->id}-commands-",
            (string) $response->headers->get('content-disposition'),
        );
        $content = $response->streamedContent();
        $this->assertStringNotContainsString('sensitive-output-must-use-separate-download', $content);
        $this->assertStringNotContainsString('filtered-out-command', $content);
        $this->assertStringNotContainsString('foreign-private-command', $content);
        $rows = $this->csvRows($content);
        $this->assertSame([
            'Execution ID',
            'Command',
            'Status',
            'Rerun from execution ID',
            'Exit code',
            'Queued at',
            'Started at',
            'Finished at',
            'Output available',
        ], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame((string) $rerun->id, $rows[1][0]);
        $this->assertSame("'=HYPERLINK(\"https://example.test\")", $rows[1][1]);
        $this->assertSame((string) $source->id, $rows[1][3]);
        $this->assertSame((string) $source->id, $rows[2][0]);
        $this->assertSame('17', $rows[2][4]);
        $this->assertSame('yes', $rows[2][8]);

        $this->actingAs($otherOwner)
            ->get(route('servers.commands.export', $server))
            ->assertForbidden();
        auth()->logout();
        $this->get(route('servers.commands.export', $server))
            ->assertRedirect(route('login'));
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
        $this->post(route('servers.commands.rerun', [
            'server' => $server,
            'execution' => $foreignExecution,
        ]))->assertNotFound();
        $this->get(route('servers.commands.output', [
            'server' => $server,
            'execution' => $foreignExecution,
        ]))->assertNotFound();

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->post(route('servers.commands.rerun', [
            'server' => $server,
            'execution' => $this->execution($server, ServerCommandExecution::STATUS_FAILED, 'hostname'),
        ]))->assertForbidden();
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+b');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
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
