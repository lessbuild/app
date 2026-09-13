<?php

namespace App\Jobs\Server;

use App\Data\OperationalDiagnosticCheck;
use App\Data\OperationalDiagnosticReport;
use App\Enums\OperationalDiagnosticCategory;
use App\Enums\ServerDiagnosticFailureStage;
use App\Exceptions\ServerDiagnosticException;
use App\Models\ServerDiagnosticSnapshot;
use App\Services\ServerDiagnosticProbe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunServerDiagnosticJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * Capture the snapshot attempt so a stale job cannot complete a later request.
     */
    public function __construct(
        public readonly int $snapshotId,
        public readonly string $attemptToken,
    ) {
        $this->timeout = max(2, (int) config('lessbuild.ssh_command_timeout', 60) + 15);
    }

    /**
     * Claim the queued snapshot, perform the fixed probe outside a transaction,
     * and persist only a matching attempt's safe report.
     */
    public function handle(ServerDiagnosticProbe $probe): void
    {
        $snapshot = $this->claim();
        if ($snapshot === null) {
            return;
        }

        if (! $snapshot->server) {
            $this->markFailed(
                ServerDiagnosticFailureStage::ServerState,
                'The server is no longer available for diagnostics.',
            );

            return;
        }

        try {
            $report = $probe->handle($snapshot->server);
        } catch (ServerDiagnosticException $exception) {
            if ($exception->stage === ServerDiagnosticFailureStage::Transport) {
                $this->requeueForRetry();
                throw $exception;
            }

            $this->markFailed($exception->stage, $this->safeFailureMessage($exception->stage));

            return;
        } catch (Throwable $exception) {
            $this->requeueForRetry();
            throw $exception;
        }

        $this->markReady($report);
    }

    /**
     * Finish a permanently failed queue attempt without retaining exception text.
     */
    public function failed(Throwable $exception): void
    {
        $this->markFailed(
            ServerDiagnosticFailureStage::Transport,
            'Unable to complete the server diagnostic connection.',
        );
    }

    private function claim(): ?ServerDiagnosticSnapshot
    {
        return DB::transaction(function (): ?ServerDiagnosticSnapshot {
            $snapshot = ServerDiagnosticSnapshot::query()
                ->whereKey($this->snapshotId)
                ->where('status', ServerDiagnosticSnapshot::STATUS_QUEUED)
                ->where('attempt_token', $this->attemptToken)
                ->where(function ($query): void {
                    $query
                        ->whereNull('lease_expires_at')
                        ->orWhere('lease_expires_at', '>', now());
                })
                ->lockForUpdate()
                ->first();

            if (! $snapshot) {
                return null;
            }

            $snapshot->update([
                'status' => ServerDiagnosticSnapshot::STATUS_RUNNING,
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'started_at' => $snapshot->started_at ?? now(),
                'finished_at' => null,
                'error' => null,
                'failure_stage' => null,
            ]);
            $snapshot->load('server');

            return $snapshot;
        });
    }

    private function markReady(OperationalDiagnosticReport $report): void
    {
        DB::transaction(function () use ($report): void {
            $snapshot = $this->lockedCurrentSnapshot(ServerDiagnosticSnapshot::STATUS_RUNNING);
            $snapshot?->update([
                'status' => ServerDiagnosticSnapshot::STATUS_READY,
                'checks' => $report->toStoredChecks(),
                'failure_stage' => null,
                'error' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ]);
        });
    }

    private function markFailed(ServerDiagnosticFailureStage $stage, string $message): void
    {
        DB::transaction(function () use ($message, $stage): void {
            $snapshot = $this->lockedCurrentSnapshot([
                ServerDiagnosticSnapshot::STATUS_QUEUED,
                ServerDiagnosticSnapshot::STATUS_RUNNING,
            ]);
            $snapshot?->update([
                'status' => ServerDiagnosticSnapshot::STATUS_FAILED,
                'checks' => (new OperationalDiagnosticReport([
                    new OperationalDiagnosticCheck(
                        'Diagnostic execution',
                        $this->failureCategory($stage),
                        false,
                        $this->safeFailureDetail($stage),
                    ),
                ]))->toStoredChecks(),
                'failure_stage' => $stage->value,
                'error' => $message,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ]);
        });
    }

    private function requeueForRetry(): void
    {
        DB::transaction(function (): void {
            $snapshot = $this->lockedCurrentSnapshot(ServerDiagnosticSnapshot::STATUS_RUNNING);
            $snapshot?->update([
                'status' => ServerDiagnosticSnapshot::STATUS_QUEUED,
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
            ]);
        });
    }

    /**
     * Lock the current attempt in one or more expected lifecycle states.
     *
     * @param  string|list<string>  $status  State or states allowed to transition.
     */
    private function lockedCurrentSnapshot(string|array $status): ?ServerDiagnosticSnapshot
    {
        $query = ServerDiagnosticSnapshot::query()
            ->whereKey($this->snapshotId)
            ->where('attempt_token', $this->attemptToken);

        if (is_array($status)) {
            $query->whereIn('status', $status);
        } else {
            $query->where('status', $status);
        }

        return $query->lockForUpdate()->first();
    }

    private function leaseSeconds(): int
    {
        return max(30, (int) config('lessbuild.server_diagnostic_lease_seconds', 180));
    }

    private function failureCategory(ServerDiagnosticFailureStage $stage): OperationalDiagnosticCategory
    {
        return $stage === ServerDiagnosticFailureStage::ServerState
            ? OperationalDiagnosticCategory::Runtime
            : OperationalDiagnosticCategory::Connectivity;
    }

    private function safeFailureMessage(ServerDiagnosticFailureStage $stage): string
    {
        return match ($stage) {
            ServerDiagnosticFailureStage::ServerState => 'The server is no longer active for diagnostics.',
            ServerDiagnosticFailureStage::HostIdentity => 'The pinned SSH host identity is unavailable.',
            ServerDiagnosticFailureStage::Transport => 'Unable to complete the server diagnostic connection.',
            ServerDiagnosticFailureStage::Response => 'The server returned an invalid diagnostic response.',
        };
    }

    private function safeFailureDetail(ServerDiagnosticFailureStage $stage): string
    {
        return match ($stage) {
            ServerDiagnosticFailureStage::ServerState => 'Server is no longer active',
            ServerDiagnosticFailureStage::HostIdentity => 'Pinned SSH host identity is unavailable',
            ServerDiagnosticFailureStage::Transport => 'The diagnostic connection failed',
            ServerDiagnosticFailureStage::Response => 'Diagnostic response was invalid',
        };
    }
}
