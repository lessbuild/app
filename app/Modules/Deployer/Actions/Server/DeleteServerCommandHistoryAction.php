<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerCommandExecution;

class DeleteServerCommandHistoryAction
{
    /**
     * Delete one terminal command-history row within its authorized parent server.
     *
     * @return bool Whether exactly one terminal execution was removed.
     */
    public function handle(Server $server, ServerCommandExecution $execution): bool
    {
        return $server->commandExecutions()
            ->whereKey($execution->id)
            ->whereIn('status', ServerCommandExecution::TERMINAL_STATUSES)
            ->delete() === 1;
    }
}
