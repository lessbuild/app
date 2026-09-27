<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\InitialiseServer;
use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RetryServerInitialization
{
    /** Try again to fetch the IP and host key of a cloud server whose initialisation failed. Returns false if it wasn't in that state. */
    public function handle(Account $account, User $actor, Server $server): bool
    {
        Gate::forUser($actor)->authorize('update', $server);

        return DB::transaction(function () use ($account, $server): bool {
            $locked = Server::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($server->id);
            if ($locked->provisioning_status !== Server::STATUS_FAILED || $locked->provisioning_failure_phase !== Server::FAILURE_INITIALIZATION) {
                return false;
            }
            if ($locked->identifier === null || $locked->provider === null || $locked->provider->trashed()) {
                throw ValidationException::withMessages(['retry' => __('The cloud server and its provider must still exist to retry.')]);
            }
            $token = (string) Str::uuid();
            $locked->forceFill(['initialization_token' => $token, 'provisioning_status' => Server::STATUS_QUEUED, 'provisioning_error' => null, 'provisioning_failure_phase' => null])->save();
            InitialiseServer::dispatch($locked->id, $token)->afterCommit();

            return true;
        });
    }
}
