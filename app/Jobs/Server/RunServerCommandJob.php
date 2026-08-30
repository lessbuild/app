<?php

namespace App\Jobs\Server;

use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RunServerCommandJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $executionId)
    {
        $this->timeout = max(2, (int) config('lessbuild.ssh_command_timeout') + 15);
    }

    public function handle(Runner $runner): void
    {
        $started = ServerCommandExecution::query()
            ->whereKey($this->executionId)
            ->where('status', ServerCommandExecution::STATUS_QUEUED)
            ->update([
                'status' => ServerCommandExecution::STATUS_RUNNING,
                'started_at' => now(),
            ]);
        if ($started === 0) {
            return;
        }

        $execution = ServerCommandExecution::with('server')->findOrFail($this->executionId);
        if ($execution->server->provisioning_status !== Server::STATUS_ACTIVE) {
            $execution->update([
                'status' => ServerCommandExecution::STATUS_FAILED,
                'output' => 'The server is no longer active.',
                'finished_at' => now(),
            ]);

            return;
        }

        $process = $runner->server($execution->server)->create()->execute($execution->command);
        $output = trim(implode(PHP_EOL, array_filter([
            $process->getOutput(),
            $process->getErrorOutput(),
        ], fn (?string $value): bool => $value !== null && $value !== '')));
        $maximum = max(1, (int) config('lessbuild.server_command_output_max_characters'));
        $output = str($output)->substr(-$maximum)->toString();
        $successful = $process->isSuccessful();

        DB::transaction(function () use ($execution, $successful, $output, $process): void {
            $locked = ServerCommandExecution::query()
                ->whereKey($execution->id)
                ->where('status', ServerCommandExecution::STATUS_RUNNING)
                ->lockForUpdate()
                ->first();

            $locked?->update([
                'status' => $successful
                    ? ServerCommandExecution::STATUS_SUCCEEDED
                    : ServerCommandExecution::STATUS_FAILED,
                'output' => $output !== '' ? $output : ($successful ? 'Command completed without output.' : 'Command failed without output.'),
                'exit_code' => $process->getExitCode(),
                'finished_at' => now(),
            ]);
        });
    }

    public function failed(\Throwable $exception): void
    {
        $maximum = max(1, (int) config('lessbuild.server_command_output_max_characters'));
        $message = str('Unable to execute command: '.$exception->getMessage())
            ->substr(-$maximum)
            ->toString();

        DB::transaction(function () use ($message): void {
            $locked = ServerCommandExecution::query()
                ->whereKey($this->executionId)
                ->whereIn('status', [ServerCommandExecution::STATUS_QUEUED, ServerCommandExecution::STATUS_RUNNING])
                ->lockForUpdate()
                ->first();

            $locked?->update([
                'status' => ServerCommandExecution::STATUS_FAILED,
                'output' => $message,
                'finished_at' => now(),
            ]);
        });
    }
}
