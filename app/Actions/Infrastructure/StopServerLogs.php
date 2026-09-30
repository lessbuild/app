<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Telemetry\RevokeIngestToken;
use App\Jobs\Infrastructure\RemoveLogAgent;
use App\Models\Server;
use App\Models\ServerLogShipping;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class StopServerLogs
{
    /**
     * Create a new StopServerLogs instance.
     *
     * @param  RevokeIngestToken  $revoke  Revokes the agent's key.
     */
    public function __construct(private readonly RevokeIngestToken $revoke) {}

    /**
     * Stop sending a server's logs: revoke the agent's key straight away and queue its removal.
     *
     * @param  User  $actor
     * @param  Server  $server
     * @return void
     */
    public function handle(User $actor, Server $server): void
    {
        Gate::forUser($actor)->authorize('update', $server);
        $shipping = ServerLogShipping::query()->with('ingestToken')->where('server_id', $server->id)->first();
        if ($shipping === null) {
            return;
        }
        if ($shipping->ingestToken !== null && $shipping->ingestToken->revoked_at === null) {
            $this->revoke->handle($actor, $shipping->ingestToken);
        }
        $shipping->forceFill(['status' => 'removing'])->save();
        RemoveLogAgent::dispatch($shipping->id)->afterCommit();
    }
}
