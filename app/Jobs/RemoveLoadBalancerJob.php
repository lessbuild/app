<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class RemoveLoadBalancerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retain the server and balancer identifiers needed to remove routing after the balancer record is deleted.
     *
     * @param  int  $serverId  Managed server identifier retained for remote work when the job runs.
     * @param  int  $loadBalancerId  Persisted load-balancer identifier used to locate its routing configuration.
     */
    public function __construct(public readonly int $serverId, public readonly int $loadBalancerId) {}

    /**
     * Remove the balancer's Caddy file, validate and reload; stop and fail the job if any remote command fails.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     * @return void Skip deleted servers; otherwise require successful remote removal and reload.
     *
     * @throws RuntimeException If the remote removal, validation or reload exits unsuccessfully.
     */
    public function handle(Runner $runner): void
    {
        $server = Server::find($this->serverId);
        if (! $server) {
            return;
        }
        $file = escapeshellarg('/etc/caddy/websites/ha-'.$this->loadBalancerId.'.conf');
        $result = $runner->server($server)->create()->execute("set -e\nrm -f -- {$file}\ncaddy validate --config /etc/caddy/Caddyfile\nsystemctl reload caddy");
        if (! $result->isSuccessful()) {
            throw new RuntimeException("Unable to remove load-balancer configuration {$this->loadBalancerId} from server {$this->serverId}.");
        }
    }
}
