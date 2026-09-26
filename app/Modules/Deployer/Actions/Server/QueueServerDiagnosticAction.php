<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Data\OperationalDiagnosticCheck;
use App\Modules\Deployer\Data\OperationalDiagnosticReport;
use App\Modules\Deployer\Enums\OperationalDiagnosticCategory;
use App\Modules\Deployer\Enums\ServerDiagnosticFailureStage;
use App\Modules\Deployer\Jobs\Server\RunServerDiagnosticJob;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerDiagnosticSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueServerDiagnosticAction
{
    /**
     * Queue one fixed diagnostic for an active server, recovering an expired
     * attempt while preventing duplicate active requests.
     *
     * @return ServerDiagnosticSnapshot The current queued, active or terminal snapshot.
     */
    public function handle(Server $server): ServerDiagnosticSnapshot
    {
        return DB::connection('deployer')->transaction(function () use ($server): ServerDiagnosticSnapshot {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            $current = $locked->diagnosticSnapshot()->lockForUpdate()->first();

            if ($current?->isActive() && ! $current->leaseExpired()) {
                return $current;
            }

            $attempt = ((int) ($current?->attempt ?? 0)) + 1;
            $token = (string) Str::uuid();

            if ($locked->provisioning_status !== Server::STATUS_ACTIVE) {
                return $this->failed(
                    $locked,
                    $attempt,
                    $token,
                    ServerDiagnosticFailureStage::ServerState,
                    'Diagnostics are available only for active servers.',
                    OperationalDiagnosticCategory::Runtime,
                    'Server is not active',
                );
            }

            if (! $locked->ssh_host_key) {
                return $this->failed(
                    $locked,
                    $attempt,
                    $token,
                    ServerDiagnosticFailureStage::HostIdentity,
                    'A pinned SSH host identity is required before diagnostics can run.',
                    OperationalDiagnosticCategory::Connectivity,
                    'Pinned SSH host identity is unavailable',
                );
            }

            if (! $locked->public_ip || ! $locked->ssh_private_key) {
                return $this->failed(
                    $locked,
                    $attempt,
                    $token,
                    ServerDiagnosticFailureStage::Transport,
                    'The server does not have the connection details required for diagnostics.',
                    OperationalDiagnosticCategory::Connectivity,
                    'Server connection details are unavailable',
                );
            }

            $snapshot = $locked->diagnosticSnapshot()->updateOrCreate(
                ['server_id' => $locked->id],
                [
                    'status' => ServerDiagnosticSnapshot::STATUS_QUEUED,
                    'checks' => null,
                    'failure_stage' => null,
                    'error' => null,
                    'attempt' => $attempt,
                    'attempt_token' => $token,
                    'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                    'started_at' => null,
                    'finished_at' => null,
                ],
            );

            RunServerDiagnosticJob::dispatch($snapshot->id, $token)->afterCommit();

            return $snapshot;
        });
    }

    private function leaseSeconds(): int
    {
        return max(30, (int) config('lessbuild.server_diagnostic_lease_seconds', 180));
    }

    private function failed(
        Server $server,
        int $attempt,
        string $token,
        ServerDiagnosticFailureStage $stage,
        string $error,
        OperationalDiagnosticCategory $category,
        string $detail,
    ): ServerDiagnosticSnapshot {
        $snapshot = $server->diagnosticSnapshot()->updateOrCreate(
            ['server_id' => $server->id],
            [
                'status' => ServerDiagnosticSnapshot::STATUS_FAILED,
                'checks' => (new OperationalDiagnosticReport([
                    new OperationalDiagnosticCheck('Diagnostic readiness', $category, false, $detail),
                ]))->toStoredChecks(),
                'failure_stage' => $stage->value,
                'error' => $error,
                'attempt' => $attempt,
                'attempt_token' => $token,
                'lease_expires_at' => null,
                'started_at' => null,
                'finished_at' => now(),
            ],
        );

        return $snapshot;
    }
}
