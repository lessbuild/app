<?php

namespace App\Actions\Server;

use App\Jobs\Server\InitialiseServerJob;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RetryServerInitializationAction
{
    /**
     * Claim a failed initialization attempt, validate its cloud provider identity, rotate the initialization token, and queue another address lookup after commit.
     *
     * @param  Server  $server  Managed server supplying its provisioning state and remote connection details.
     * @return bool Whether an eligible initialization failure was reset and queued; false for other server states.
     *
     * @throws ValidationException If the server lacks its cloud provider or remote identifier.
     */
    public function handle(Server $server): bool
    {
        return DB::transaction(function () use ($server): bool {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);

            if ($locked->provisioning_status !== Server::STATUS_FAILED
                || $locked->provisioning_failure_phase !== Server::FAILURE_INITIALIZATION) {
                return false;
            }

            if (! $locked->identifier || ! $locked->provider) {
                throw ValidationException::withMessages([
                    'retry' => __('The cloud server and its provider must still be available before initialization can be retried.'),
                ]);
            }

            $locked->update([
                'initialization_token' => (string) Str::uuid(),
                'provisioning_status' => Server::STATUS_QUEUED,
                'provisioning_error' => null,
                'provisioning_failure_phase' => null,
            ]);

            InitialiseServerJob::dispatch($locked)->afterCommit();

            return true;
        });
    }
}
