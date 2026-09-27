<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\FetchServerLog;
use App\Models\Account;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Models\User;
use App\Services\Infrastructure\ServerLogs;
use Illuminate\Support\Facades\Gate;

final class RefreshServerLog
{
    /** Fetch a fresh copy of one of an active server's logs. Returns false for an unknown log or a server that isn't active. */
    public function handle(Account $account, User $actor, Server $server, string $type): bool
    {
        Gate::forUser($actor)->authorize('useService', [$account, 'infrastructure']);
        $server = Server::query()->where('account_id', $account->id)->findOrFail($server->id);
        if (! array_key_exists($type, ServerLogs::TYPES) || $server->provisioning_status !== Server::STATUS_ACTIVE) {
            return false;
        }
        $server->logSnapshots()->updateOrCreate(['type' => $type], ['status' => ServerLogSnapshot::STATUS_QUEUED, 'error' => null]);
        FetchServerLog::dispatch($server->id, $type);

        return true;
    }
}
