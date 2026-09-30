<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Server;
use App\Services\Infrastructure\ServerShell;
use App\Services\Infrastructure\ServerSnapshots;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/** Switches a server's Node.js to the chosen major version with n (installed at setup), for every site on it. */
final class ApplyServerNodeVersion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds the switch may take.
     *
     * @var int
     */
    public int $timeout = 600;

    /**
     * Create a new ApplyServerNodeVersion instance.
     *
     * @param  int  $serverId  The server, read again so the version is current.
     */
    public function __construct(public readonly int $serverId) {}

    /**
     * Install the version with n (installing n first if the server lacks it) and check it's the one on the path.
     *
     * @param  ServerShell  $shell
     * @param  ServerSnapshots  $snapshots
     * @return void
     */
    public function handle(ServerShell $shell, ServerSnapshots $snapshots): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null || $server->node_version === null || $server->provisioning_status !== Server::STATUS_ACTIVE || preg_match('/\A\d{2}\z/', $server->node_version) !== 1) {
            return;
        }
        $major = $server->node_version;
        $snapshots->before($server, 'Before switching Node.js to '.$major);
        $result = $shell->run($server, "set -Eeuo pipefail\ncommand -v n >/dev/null 2>&1 || npm install -g n\nn {$major}\nhash -r\nnode --version | grep -q '^v{$major}\\.'", true);
        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput) !== '' ? trim($result->errorOutput) : 'Couldn’t switch the Node.js version.');
        }
    }
}
