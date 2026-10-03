<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Enums\ServerType;
use App\Jobs\Infrastructure\SetUpReadReplica;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AddReadReplica
{
    /**
     * Make one of the account's database servers a read replica of another: same engine, both active, neither
     * already part of another replication. The replica's current data is moved aside and replaced by a copy of the
     * primary, so the replica's name must be typed to confirm. Running it again on a replica whose setup failed starts
     * over.
     *
     * @param  User  $actor
     * @param  Server  $primary
     * @param  Server  $replica
     * @param  string  $confirmation  The replica's name, typed.
     * @return Server
     */
    public function handle(User $actor, Server $primary, Server $replica, string $confirmation): Server
    {
        Gate::forUser($actor)->authorize('runCommands', $primary);
        Gate::forUser($actor)->authorize('runCommands', $replica);

        $error = match (true) {
            $primary->account_id !== $replica->account_id || $primary->is($replica) => __('Choose another database server in this account.'),
            $primary->type !== ServerType::Database || $replica->type !== ServerType::Database => __('Read replicas are for database servers.'),
            $primary->database_engine === null || $primary->database_engine !== $replica->database_engine => __('The replica must run the same database as the primary.'),
            $primary->provisioning_status !== Server::STATUS_ACTIVE || $replica->provisioning_status !== Server::STATUS_ACTIVE => __('Both servers must be active.'),
            $primary->replica_of_server_id !== null => __('A replica can’t have replicas of its own. Choose its primary instead.'),
            $replica->replicas()->exists() => __(':name has replicas of its own.', ['name' => $replica->name]),
            $replica->replica_of_server_id !== null && ! ($replica->replica_of_server_id === $primary->id && $replica->replication_status === 'failed') => __(':name is already a replica.', ['name' => $replica->name]),
            $primary->public_ip === null || $replica->public_ip === null => __('Both servers need an address.'),
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['replica_server_id' => $error]);
        }
        if (trim($confirmation) !== $replica->name) {
            throw ValidationException::withMessages(['confirmation' => __('Type :name to confirm.', ['name' => $replica->name])]);
        }

        $replica->forceFill([
            'replica_of_server_id' => $primary->id,
            'replication_status' => 'setting_up',
            'replication_password' => Str::random(40),
            'replication_lag_seconds' => null,
            'replication_error' => null,
            'replication_checked_at' => null,
        ])->save();
        SetUpReadReplica::dispatch($replica->id);

        return $replica;
    }
}
