<?php

namespace App\Actions\Server;

use App\Jobs\Server\RefreshServerLogJob;
use App\Models\Server;
use App\Models\ServerLogSnapshot;

class QueueServerLogRefreshAction
{
    /**
     * Queue an allowlisted server-log refresh only while the server is active.
     *
     * @return bool Whether a refresh snapshot was queued.
     */
    public function handle(Server $server, string $type): bool
    {
        $server->refresh();
        if (! in_array($type, CollectServerLogAction::TYPES, true)
            || $server->provisioning_status !== Server::STATUS_ACTIVE) {
            return false;
        }

        $server->logSnapshots()->updateOrCreate(
            ['type' => $type],
            [
                'status' => ServerLogSnapshot::STATUS_QUEUED,
                'error' => null,
            ],
        );

        RefreshServerLogJob::dispatch($server->id, $type);

        return true;
    }
}
