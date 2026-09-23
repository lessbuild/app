<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Models\Server;
use Illuminate\Support\Facades\DB;

class DeleteServerAction
{
    /**
     * Delete a server and let its observer perform provider and child-resource cleanup inside the existing transaction.
     *
     * @param  Server  $server  Server whose complete local resource tree is being removed.
     */
    public function handle(Server $server): void
    {
        DB::connection('deployer')->transaction(fn (): mixed => $server->delete());
    }
}
