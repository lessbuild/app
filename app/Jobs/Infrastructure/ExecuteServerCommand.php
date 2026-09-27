<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Services\Infrastructure\ServerShell;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Runs a queued command over SSH and records its output (the last part, if it's long) and exit code. Runs once. */
final class ExecuteServerCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $executionId)
    {
        $this->timeout = max(2, (int) config('infrastructure.ssh_command_timeout') + 15);
    }

    public function handle(ServerShell $shell): void
    {
        if (ServerCommandExecution::query()->whereKey($this->executionId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]) === 0) {
            return;
        }
        $execution = ServerCommandExecution::query()->with('server')->findOrFail($this->executionId);
        if ($execution->server->provisioning_status !== Server::STATUS_ACTIVE) {
            $this->finish('failed', 'The server is no longer active.', null);

            return;
        }
        $result = $shell->run($execution->server, $execution->command, logOutput: true);
        $output = $result->combined();
        $this->finish($result->successful() ? 'succeeded' : 'failed', $output !== '' ? $output : ($result->successful() ? 'Finished without output.' : 'Failed without output.'), $result->exitCode);
    }

    public function failed(Throwable $exception): void
    {
        $this->finish('failed', 'Couldn’t run the command: '.$exception->getMessage(), null, ['queued', 'running']);
    }

    /** @param list<string> $from */
    private function finish(string $status, string $output, ?int $exitCode, array $from = ['running']): void
    {
        $execution = ServerCommandExecution::query()->whereKey($this->executionId)->whereIn('status', $from)->first();
        $execution?->forceFill([
            'status' => $status, 'exit_code' => $exitCode, 'finished_at' => CarbonImmutable::now('UTC'),
            'output' => mb_substr($output, -max(1, (int) config('infrastructure.server_command_output_max_characters'))),
        ])->save();
    }
}
