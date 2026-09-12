<?php

namespace App\Services;

use App\Models\Server;

class ServerProvisioningCallbackGuard
{
    /**
     * Check whether the supplied attempt token matches the current server token.
     *
     * @param  Server  $server  The freshly locked server row.
     * @param  mixed  $attempt  Raw callback attempt token.
     * @return bool Whether this callback belongs to the current attempt.
     */
    public function matchesAttempt(Server $server, mixed $attempt): bool
    {
        return ! $server->provisioning_token
            || hash_equals($server->provisioning_token, (string) $attempt);
    }

    /**
     * Check attempt identity and the mutable provisioning states accepted by status/failure callbacks.
     *
     * @param  Server  $server  The freshly locked server row.
     * @param  mixed  $attempt  Raw callback attempt token.
     * @return bool Whether this callback can still change provisioning state.
     */
    public function acceptsLifecycle(Server $server, mixed $attempt): bool
    {
        return $this->matchesAttempt($server, $attempt)
            && in_array($server->provisioning_status, [
                Server::STATUS_QUEUED,
                Server::STATUS_WAITING_FOR_IP,
                Server::STATUS_PROVISIONING,
            ], true);
    }
}
