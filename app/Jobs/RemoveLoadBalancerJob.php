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

    public function __construct(public readonly int $serverId, public readonly int $loadBalancerId) {}

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
