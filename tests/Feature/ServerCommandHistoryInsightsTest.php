<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerCommandHistoryInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_status_and_output_counts_for_only_the_selected_server(): void
    {
        [$owner, $server] = $this->resources();
        $otherServer = $owner->servers()->create(['name' => 'Recovery']);
        $this->execution($server, ServerCommandExecution::STATUS_QUEUED, 'queued command');
        $this->execution($server, ServerCommandExecution::STATUS_RUNNING, 'running command', 'partial-output-secret');
        $this->execution($server, ServerCommandExecution::STATUS_SUCCEEDED, 'successful command', 'successful-output-secret');
        $this->execution($server, ServerCommandExecution::STATUS_FAILED, 'failed command');
        $this->execution($server, ServerCommandExecution::STATUS_CANCELED, 'canceled command');
        $this->execution($otherServer, ServerCommandExecution::STATUS_FAILED, 'other-server-command', 'other-output-secret');

        $this->actingAs($owner)->get(route('servers.commands.index', $server))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 5,
                'active' => 2,
                'succeeded' => 1,
                'failed' => 1,
                'canceled' => 1,
                'output' => 2,
            ])
            ->assertSee('Matching commands')
            ->assertSee('Active commands')
            ->assertSee('Succeeded')
            ->assertSee('Failed')
            ->assertSee('Canceled')
            ->assertSee('Output retained')
            ->assertSee('data-command-execution', false)
            ->assertDontSee('<table', false)
            ->assertDontSee('other-server-command')
            ->assertDontSee('partial-output-secret')
            ->assertDontSee('successful-output-secret')
            ->assertDontSee('other-output-secret');
    }

    public function test_command_history_fragment_reuses_server_scoping_for_the_read_only_dialog(): void
    {
        [$owner, $server] = $this->resources();
        $execution = $this->execution($server, ServerCommandExecution::STATUS_SUCCEEDED, 'uptime', 'output-secret');

        $response = $this->actingAs($owner)->get(route('servers.commands.index', [
            'server' => $server,
            'fragment' => 'server-command-history',
        ]));

        $response
            ->assertSuccessful()
            ->assertViewIs('components.scenes.servers.command-history-content')
            ->assertSee('data-command-execution', false)
            ->assertSee('uptime')
            ->assertSee(route('servers.commands.output', ['server' => $server, 'execution' => $execution]))
            ->assertDontSee('output-secret')
            ->assertDontSee('<html', false);
    }

    public function test_retained_output_is_lazy_loaded_in_a_scoped_inspector(): void
    {
        [$owner, $server] = $this->resources();
        $output = "first line\n<script>alert('secret')</script>";
        $execution = $this->execution($server, ServerCommandExecution::STATUS_FAILED, 'systemctl status caddy', $output);

        $this->actingAs($owner)
            ->get(route('servers.commands.index', $server))
            ->assertSuccessful()
            ->assertSee('View output')
            ->assertSee('Download output')
            ->assertSee('dialog=server-command-output-'.$execution->id, false)
            ->assertDontSee('first line')
            ->assertDontSee("alert('secret')", false);

        $this->get(route('servers.commands.index', [
            'server' => $server,
            'dialog' => 'server-command-output-'.$execution->id,
        ]))
            ->assertSuccessful()
            ->assertViewHas('selectedOutputExecution', fn ($selected): bool => $selected?->is($execution) ?? false)
            ->assertSee('data-modal-initial-open="true"', false)
            ->assertDontSee('first line')
            ->assertDontSee("alert('secret')", false);

        $this->get(route('servers.commands.output', [
            'server' => $server,
            'execution' => $execution,
            'fragment' => 'server-command-output',
        ]))
            ->assertSuccessful()
            ->assertViewIs('components.scenes.servers.command-output-content')
            ->assertSee('first line')
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>', false)
            ->assertSee('data-command-output-content', false)
            ->assertSee(route('servers.commands.output', [
                'server' => $server,
                'execution' => $execution,
            ]));
    }

    public function test_retained_output_inspector_preserves_foreign_and_missing_output_denials(): void
    {
        [$owner, $server] = $this->resources();
        $otherServer = $owner->servers()->create(['name' => 'Recovery']);
        $execution = $this->execution($server, ServerCommandExecution::STATUS_SUCCEEDED, 'uptime', 'output-secret');
        $foreignExecution = $this->execution($otherServer, ServerCommandExecution::STATUS_SUCCEEDED, 'hostname', 'foreign-output');
        $withoutOutput = $this->execution($server, ServerCommandExecution::STATUS_CANCELED, 'whoami');
        $intruder = User::factory()->create();

        $this->actingAs($owner)->get(route('servers.commands.output', [
            'server' => $server,
            'execution' => $foreignExecution,
            'fragment' => 'server-command-output',
        ]))->assertNotFound();

        $this->actingAs($owner)->get(route('servers.commands.output', [
            'server' => $server,
            'execution' => $withoutOutput,
            'fragment' => 'server-command-output',
        ]))->assertNotFound();

        $this->actingAs($intruder)->get(route('servers.commands.output', [
            'server' => $server,
            'execution' => $execution,
            'fragment' => 'server-command-output',
        ]))->assertForbidden();
    }

    public function test_metrics_apply_status_and_queued_date_filters(): void
    {
        [$owner, $server] = $this->resources();
        $matching = $this->execution(
            $server,
            ServerCommandExecution::STATUS_FAILED,
            'matching failed command',
            'matching-output-secret',
            '2026-08-20 12:00:00',
        );
        $this->execution(
            $server,
            ServerCommandExecution::STATUS_FAILED,
            'failed before window',
            null,
            '2026-08-19 23:59:59',
        );
        $this->execution(
            $server,
            ServerCommandExecution::STATUS_SUCCEEDED,
            'successful in window',
            null,
            '2026-08-20 13:00:00',
        );

        $this->actingAs($owner)->get(route('servers.commands.index', [
            $server,
            'status' => ServerCommandExecution::STATUS_FAILED,
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-20',
        ]))
            ->assertSuccessful()
            ->assertViewHas('executions', fn ($executions): bool => $executions->count() === 1
                && $executions->sole()->id === $matching->id)
            ->assertViewHas('metrics', [
                'total' => 1,
                'active' => 0,
                'succeeded' => 0,
                'failed' => 1,
                'canceled' => 0,
                'output' => 1,
            ])
            ->assertDontSee('matching-output-secret');
    }

    public function test_empty_date_filter_has_explicit_zero_metrics(): void
    {
        [$owner, $server] = $this->resources();
        $this->execution(
            $server,
            ServerCommandExecution::STATUS_SUCCEEDED,
            'older successful command',
            null,
            '2026-08-20 12:00:00',
        );

        $this->actingAs($owner)->get(route('servers.commands.index', [
            $server,
            'date_from' => '2026-08-21',
        ]))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'active' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'canceled' => 0,
                'output' => 0,
            ])
            ->assertSee('No commands match these filters');
    }

    /** @return array{User, Server} */
    private function resources(): array
    {
        $owner = User::factory()->create();
        $server = $owner->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        return [$owner, $server];
    }

    private function execution(
        Server $server,
        string $status,
        string $command,
        ?string $output = null,
        ?string $createdAt = null,
    ): ServerCommandExecution {
        return $server->commandExecutions()->create([
            'user_id' => $server->user_id,
            'command' => $command,
            'status' => $status,
            'output' => $output,
            ...($createdAt === null ? [] : [
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]),
        ]);
    }
}
