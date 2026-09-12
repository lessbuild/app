<?php

namespace App\Actions\Server;

use App\Models\Server;
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
        DB::transaction(fn (): mixed => $server->delete());
    }
}
