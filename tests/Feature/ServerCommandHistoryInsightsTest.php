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
            ->assertDontSee('other-server-command')
            ->assertDontSee('partial-output-secret')
            ->assertDontSee('successful-output-secret')
            ->assertDontSee('other-output-secret');
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
