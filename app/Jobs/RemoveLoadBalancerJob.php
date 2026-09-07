<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
     * Remove the balancer's Caddy file and request validation and reload when its server still exists; the command result is not inspected.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     */
    public function handle(Runner $runner): void
    {
        $server = Server::find($this->serverId);
        if (! $server) {
            return;
        }
        $file = escapeshellarg('/etc/caddy/websites/ha-'.$this->loadBalancerId.'.conf');
        $runner->server($server)->create()->execute("rm -f -- {$file}\ncaddy validate --config /etc/caddy/Caddyfile\nsystemctl reload caddy");
    }
}
