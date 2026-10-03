<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Server;
use App\Models\ServerLogShipping;
use App\Services\Infrastructure\LogShipperScript;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Stops and removes a server's log agent, then forgets the shipping. */
final class RemoveLogAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Create a new RemoveLogAgent instance.
     *
     * @param  int  $shippingId  The server's log shipping.
     */
    public function __construct(public readonly int $shippingId) {}

    /**
     * Remove the agent (its key is already revoked, so it can't send even if this fails) and delete the record.
     *
     * @param  ServerShell  $shell
     * @param  LogShipperScript  $scripts
     * @return void
     */
    public function handle(ServerShell $shell, LogShipperScript $scripts): void
    {
        $shipping = ServerLogShipping::query()->with('server')->find($this->shippingId);
        if ($shipping === null) {
            return;
        }
        if ($shipping->server->provisioning_status === Server::STATUS_ACTIVE) {
            $shell->run($shipping->server, $scripts->remove());
        }
        $shipping->delete();
    }
}
