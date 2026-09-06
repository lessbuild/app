<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommandCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_center_requires_a_verified_account(): void
    {
        $this->get(route('commands.index'))->assertRedirect(route('login'));
        $this->get(route('commands.export'))->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->get(route('commands.index'))->assertRedirect(route('verification.notice'));
        $this->actingAs($unverified)->get(route('commands.export'))->assertRedirect(route('verification.notice'));
    }

    public function test_owner_can_review_filtered_command_metadata_without_secrets_or_foreign_rows(): void
    {
        $owner = User::factory()->create();
        $first = $this->server($owner, 'First server');
        $first->update(['display_name' => '=SUM(1,1)']);
        $second = $this->server($owner, 'Second server');
        $foreignServer = $this->server(User::factory()->create(), 'Foreign server');
        $queued = $this->execution($owner, $first, ServerCommandExecution::STATUS_QUEUED, 'owner-queued-secret');
        $running = $this->execution($owner, $second, ServerCommandExecution::STATUS_RUNNING, 'owner-running-secret');
        $completed = $this->execution($owner, $first, ServerCommandExecution::STATUS_SUCCEEDED, 'owner-completed-secret');
        $this->execution($foreignServer->user, $foreignServer, ServerCommandExecution::STATUS_QUEUED, 'foreign-command-secret');

        $this->actingAs($owner)->get(route('commands.index', ['active' => 1]))
            ->assertSuccessful()
            ->assertViewHas('executions', function ($executions) use ($queued, $running): bool {
                return $executions->pluck('id')->sort()->values()->all() === collect([$queued->id, $running->id])->sort()->values()->all()
                    && $executions->every(fn (ServerCommandExecution $execution): bool => ! array_key_exists('command', $execution->getAttributes())
                        && ! array_key_exists('output', $execution->getAttributes()));
            })
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 2 && $metrics['active'] === 2)
            ->assertSee('Command Center')
            ->assertSee('name="active" value="1" checked', false)
            ->assertSee('Refresh status')
            ->assertSee('Queued or running commands may change. Refresh to load their latest state.')
            ->assertSee(route('commands.index', ['active' => '1', 'page' => 1]))
            ->assertSee(route('commands.export', ['active' => 1]))
            ->assertSee(route('servers.commands.index', ['server' => $first, 'execution' => $queued->id]))
            ->assertSee(route('servers.commands.index', ['server' => $second, 'execution' => $running->id]))
            ->assertDontSee('owner-queued-secret')
            ->assertDontSee('owner-running-secret')
            ->assertDontSee('owner-completed-secret')
            ->assertDontSee('foreign-command-secret')
            ->assertDontSee('Foreign server');

        $this->actingAs($owner)->get(route('commands.index', ['status' => ServerCommandExecution::STATUS_SUCCEEDED]))
            ->assertSuccessful()
            ->assertDontSee('Refresh status');

        $export = $this->actingAs($owner)->get(route('commands.export', ['active' => 1]))
            ->assertSuccessful()
            ->assertDownload()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->streamedContent();
        $this->assertStringContainsString("'=SUM(1,1)", $export);
        $this->assertStringNotContainsString('owner-queued-secret', $export);
        $this->assertStringNotContainsString('owner-running-secret', $export);
        $this->assertStringNotContainsString('owner-completed-secret', $export);
        $this->assertStringNotContainsString('foreign-command-secret', $export);
    }

    public function test_filters_are_combined_normalized_and_preserved_in_pagination(): void
    {
        $owner = User::factory()->create();
        $server = $this->server($owner, 'Busy server');
        $otherServer = $this->server($owner, 'Other server');
        foreach (range(1, 26) as $index) {
            $execution = $this->execution($owner, $server, ServerCommandExecution::STATUS_FAILED, "secret-{$index}");
            $execution->forceFill(['created_at' => '2026-08-15 12:00:00'])->save();
        }
        $withoutOutput = $this->execution($owner, $server, ServerCommandExecution::STATUS_FAILED, 'missing-output-secret');
        $withoutOutput->update(['output' => null, 'created_at' => '2026-08-15 12:00:00']);
        $this->execution($owner, $otherServer, ServerCommandExecution::STATUS_FAILED, 'other-server-secret');

        $filters = [
            'server_id' => $server->id,
            'status' => ServerCommandExecution::STATUS_FAILED,
            'output' => 'available',
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-01',
        ];
        $this->actingAs($owner)->get(route('commands.index', $filters))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'server_id' => $server->id,
                'status' => ServerCommandExecution::STATUS_FAILED,
                'output' => 'available',
                'active' => null,
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-20',
            ])
            ->assertViewHas('executions', fn ($executions): bool => $executions->count() === 25 && $executions->lastPage() === 2)
            ->assertSee('page=2', false)
            ->assertSee('server_id='.$server->id, false)
            ->assertSee('status=failed', false)
            ->assertSee('output=available', false)
            ->assertSee('date_from=2026-08-01', false)
            ->assertSee('date_to=2026-08-20', false)
            ->assertDontSee('missing-output-secret')
            ->assertDontSee('other-server-secret');

        $exportRows = $this->csvRows($this->actingAs($owner)->get(route('commands.export', [
            ...$filters,
            'output' => 'missing',
        ]))->assertSuccessful()->streamedContent());
        $this->assertCount(2, $exportRows);
        $this->assertSame((string) $withoutOutput->id, $exportRows[1][0]);
        $this->assertSame('', $exportRows[1][7]);
        $this->assertSame('no', $exportRows[1][8]);
    }

    public function test_invalid_filters_are_ignored_with_an_explicit_empty_state(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('commands.index', [
            'server_id' => 'invalid',
            'status' => 'unknown',
            'active' => 'sometimes',
            'date_from' => 'bad-date',
        ]))
            ->assertSuccessful()
            ->assertViewHas('filters', [
                'server_id' => null,
                'status' => null,
                'output' => null,
                'active' => null,
                'date_from' => null,
                'date_to' => null,
            ])
            ->assertSee('No commands have been run yet');
    }

    public function test_focused_server_history_shows_only_the_selected_owned_execution(): void
    {
        $owner = User::factory()->create();
        $server = $this->server($owner, 'Focus server');
        $focused = $this->execution($owner, $server, ServerCommandExecution::STATUS_QUEUED, 'focused-command');
        $this->execution($owner, $server, ServerCommandExecution::STATUS_QUEUED, 'other-command');

        $this->actingAs($owner)->get(route('servers.commands.index', [
            'server' => $server,
            'execution' => $focused->id,
        ]))
            ->assertSuccessful()
            ->assertViewHas('executions', fn ($executions): bool => $executions->count() === 1
                && $executions->sole()->id === $focused->id)
            ->assertSee('Focused on execution #'.$focused->id.'.')
            ->assertSee('focused-command')
            ->assertDontSee('other-command');
    }

    private function server(User $user, string $name): Server
    {
        return $user->servers()->create([
            'name' => $name,
            'display_name' => $name,
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
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

    private function execution(User $user, Server $server, string $status, string $secret): ServerCommandExecution
    {
        return $server->commandExecutions()->create([
            'user_id' => $user->id,
            'command' => $secret,
            'output' => "output-{$secret}",
            'status' => $status,
        ]);
    }
}
