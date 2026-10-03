<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Services\Infrastructure\ReplicationScripts;
use Illuminate\Validation\ValidationException;

final class PromoteReadReplica
{
    /**
     * Create a new PromoteReadReplica instance.
     *
     * @param  RunServerCommand  $run  Runs the promotion on the replica, in its command history.
     * @param  ReplicationScripts  $scripts  Renders the promotion.
     */
    public function __construct(private readonly RunServerCommand $run, private readonly ReplicationScripts $scripts) {}

    /**
     * Turn a read replica into a standalone database server that accepts writes: it stops following its primary,
     * keeping everything it has copied. Use it to replace a failed primary, or to keep a copy separately. The
     * replica's name must be typed to confirm.
     *
     * @param  User  $actor
     * @param  Server  $replica
     * @param  string  $confirmation  The replica's name, typed.
     * @return ServerCommandExecution
     */
    public function handle(User $actor, Server $replica, string $confirmation): ServerCommandExecution
    {
        if ($replica->replica_of_server_id === null) {
            throw ValidationException::withMessages(['confirmation' => __('This server isn’t a read replica.')]);
        }
        if (trim($confirmation) !== $replica->name) {
            throw ValidationException::withMessages(['confirmation' => __('Type :name to confirm.', ['name' => $replica->name])]);
        }
        $execution = $this->run->handle($replica->account, $actor, $replica, $this->scripts->promote($replica));
        $replica->forceFill([
            'replica_of_server_id' => null,
            'replication_status' => null,
            'replication_password' => null,
            'replication_lag_seconds' => null,
            'replication_error' => null,
            'replication_checked_at' => null,
        ])->save();

        return $execution;
    }
}
