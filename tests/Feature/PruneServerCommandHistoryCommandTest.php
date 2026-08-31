<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PruneServerCommandHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prunes_only_expired_terminal_history_and_preserves_newer_lineage(): void
    {
        [$user, $server] = $this->resources();
        $old = now()->subDays(181);
        $source = $this->execution($user, $server, ServerCommandExecution::STATUS_SUCCEEDED, $old, 'old source');
        $expired = collect([
            $source,
            $this->execution($user, $server, ServerCommandExecution::STATUS_FAILED, $old, 'old failed'),
            $this->execution($user, $server, ServerCommandExecution::STATUS_CANCELED, $old, 'old canceled'),
        ]);
        $recentRerun = $this->execution(
            $user,
            $server,
            ServerCommandExecution::STATUS_SUCCEEDED,
            now()->subDays(10),
            'old source',
            $source,
        );
        $preserved = collect([
            $recentRerun,
            $this->execution($user, $server, ServerCommandExecution::STATUS_QUEUED, $old, 'old queued'),
            $this->execution($user, $server, ServerCommandExecution::STATUS_RUNNING, $old, 'old running'),
            $this->execution($user, $server, ServerCommandExecution::STATUS_FAILED, now()->subDays(179), 'recent failed'),
        ]);

        $this->assertSame(0, Artisan::call('lessbuild:commands:prune'));
        $this->assertStringContainsString('Pruned 3 server command record(s) older than 180 day(s).', Artisan::output());
        $expired->each(fn (ServerCommandExecution $execution) => $this->assertModelMissing($execution));
        $preserved->each(fn (ServerCommandExecution $execution) => $this->assertModelExists($execution));
        $this->assertNull($recentRerun->fresh()->rerun_from_execution_id);
        $this->assertNull($recentRerun->fresh()->rerunFrom);
    }

    public function test_command_supports_an_explicit_retention_window(): void
    {
        [$user, $server] = $this->resources();
        $expired = $this->execution(
            $user,
            $server,
            ServerCommandExecution::STATUS_SUCCEEDED,
            now()->subDays(31),
            'custom expired',
        );
        $recent = $this->execution(
            $user,
            $server,
            ServerCommandExecution::STATUS_SUCCEEDED,
            now()->subDays(29),
            'custom recent',
        );

        $this->assertSame(0, Artisan::call('lessbuild:commands:prune', ['--days' => '30']));
        $this->assertModelMissing($expired);
        $this->assertModelExists($recent);
    }

    public function test_command_rejects_invalid_retention_without_deleting_history(): void
    {
        [$user, $server] = $this->resources();
        $execution = $this->execution(
            $user,
            $server,
            ServerCommandExecution::STATUS_FAILED,
            now()->subYear(),
            'must remain',
        );

        foreach (['0', '-1', '1.5', 'invalid'] as $days) {
            $this->assertSame(1, Artisan::call('lessbuild:commands:prune', ['--days' => $days]));
            $this->assertStringContainsString('Retention days must be a positive integer.', Artisan::output());
            $this->assertModelExists($execution);
        }
    }

    /** @return array{User, Server} */
    private function resources(): array
    {
        $user = User::factory()->create();
        $server = $user->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);

        return [$user, $server];
    }

    private function execution(
        User $user,
        Server $server,
        string $status,
        mixed $createdAt,
        string $command,
        ?ServerCommandExecution $rerunFrom = null,
    ): ServerCommandExecution {
        return $server->commandExecutions()->create([
            'user_id' => $user->id,
            'command' => $command,
            'status' => $status,
            'rerun_from_execution_id' => $rerunFrom?->id,
            'output' => in_array($status, ServerCommandExecution::TERMINAL_STATUSES, true)
                ? 'encrypted retained output'
                : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
