<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\DiagnoseServer;
use App\Models\Account;
use App\Models\Server;
use App\Models\ServerDiagnosticSnapshot;
use App\Models\User;
use App\Services\Infrastructure\ServerDiagnostics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RunServerDiagnostics
{
    /**
     * Create a new RunServerDiagnostics instance.
     *
     * Starts a diagnostic run on a server.
     *
     * @param  ServerDiagnostics  $diagnostics  Checks the server can be diagnosed at all.
     */
    public function __construct(private readonly ServerDiagnostics $diagnostics) {}

    /**
     * Queue a diagnostic run, or return the one already running. If the server can't be diagnosed yet (not active, no
     * pinned host key), the snapshot records why at once.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  Server  $server
     * @return ServerDiagnosticSnapshot
     */
    public function handle(Account $account, User $actor, Server $server): ServerDiagnosticSnapshot
    {
        Gate::forUser($actor)->authorize('view', $server);

        return DB::transaction(function () use ($account, $server): ServerDiagnosticSnapshot {
            $locked = Server::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($server->id);
            $current = $locked->diagnosticSnapshot()->lockForUpdate()->first();
            if ($current?->isRunning() === true) {
                return $current;
            }
            $now = CarbonImmutable::now('UTC');
            $token = (string) Str::uuid();
            $readiness = $this->diagnostics->readiness($locked);
            $snapshot = $current ?? (new ServerDiagnosticSnapshot)->forceFill(['server_id' => $locked->id]);
            $snapshot->forceFill([
                'attempt' => ($current->attempt ?? 0) + 1, 'attempt_token' => $token, 'started_at' => null,
                ...($readiness === null
                    ? ['status' => 'queued', 'checks' => null, 'failure_stage' => null, 'error' => null, 'lease_expires_at' => $now->addSeconds((int) config('infrastructure.server_diagnostic_lease_seconds', 180)), 'finished_at' => null]
                    : ['status' => 'failed', 'checks' => [['name' => 'Diagnostic readiness', 'category' => $readiness[0] === 'server_state' ? 'runtime' : 'connectivity', 'passed' => false, 'detail' => $readiness[1]]],
                        'failure_stage' => $readiness[0], 'error' => $readiness[1], 'lease_expires_at' => null, 'finished_at' => $now]),
            ])->save();
            if ($readiness === null) {
                DiagnoseServer::dispatch($snapshot->id, $token)->afterCommit();
            }

            return $snapshot;
        });
    }
}
