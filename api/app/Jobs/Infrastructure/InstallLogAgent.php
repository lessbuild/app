<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Server;
use App\Models\ServerLogShipping;
use App\Services\Infrastructure\LogShipperScript;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/** Installs the log agent on a server with its ingest key. The queued payload is encrypted, since it holds the key. */
final class InstallLogAgent implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds the install may take.
     *
     * @var int
     */
    public int $timeout = 300;

    /**
     * Create a new InstallLogAgent instance.
     *
     * @param  int  $shippingId  The server's log shipping.
     * @param  string  $secret  The ingest key the agent sends with.
     */
    public function __construct(public readonly int $shippingId, public readonly string $secret) {}

    /**
     * Install and start the agent, and record whether it's running.
     *
     * @param  ServerShell  $shell
     * @param  LogShipperScript  $scripts
     * @return void
     */
    public function handle(ServerShell $shell, LogShipperScript $scripts): void
    {
        $shipping = ServerLogShipping::query()->with('server')->find($this->shippingId);
        if ($shipping === null || $shipping->server->provisioning_status !== Server::STATUS_ACTIVE) {
            return;
        }
        $result = $shell->run($shipping->server, $scripts->install(route('api.ingest'), $this->secret));
        $shipping->forceFill($result->successful()
            ? ['status' => 'active', 'last_error' => null, 'installed_at' => now()]
            : ['status' => 'failed', 'last_error' => Str::limit(trim($result->errorOutput) !== '' ? trim($result->errorOutput) : 'The agent didn’t start.', 480)])->save();
    }
}
