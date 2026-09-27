<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\ServerDiagnosticSnapshot;
use App\Services\Infrastructure\ServerDiagnostics;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Runs one diagnostic attempt; a newer attempt (different token) makes this one do nothing. */
final class DiagnoseServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $snapshotId, public readonly string $attempt) {}

    public function handle(ServerDiagnostics $diagnostics): void
    {
        if (ServerDiagnosticSnapshot::query()->whereKey($this->snapshotId)->where('attempt_token', $this->attempt)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]) === 0) {
            return;
        }
        $snapshot = ServerDiagnosticSnapshot::query()->with('server')->findOrFail($this->snapshotId);
        try {
            $checks = $diagnostics->run($snapshot->server);
            $this->finish(['status' => 'ready', 'checks' => $checks, 'failure_stage' => null, 'error' => null]);
        } catch (RuntimeException $exception) {
            [$stage, $message] = array_pad(explode(': ', $exception->getMessage(), 2), 2, 'The diagnostic failed.');
            $stage = in_array($stage, ['server_state', 'host_identity', 'transport', 'response'], true) ? $stage : 'transport';
            $this->finish(['status' => 'failed', 'checks' => [['name' => 'Diagnostic run', 'category' => 'connectivity', 'passed' => false, 'detail' => $message]], 'failure_stage' => $stage, 'error' => $message]);
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->finish(['status' => 'failed', 'failure_stage' => 'transport', 'error' => 'The diagnostic couldn’t finish.']);
    }

    /** @param array<string, mixed> $values */
    private function finish(array $values): void
    {
        ServerDiagnosticSnapshot::query()->whereKey($this->snapshotId)->where('attempt_token', $this->attempt)->first()
            ?->forceFill([...$values, 'lease_expires_at' => null, 'finished_at' => CarbonImmutable::now('UTC')])->save();
    }
}
