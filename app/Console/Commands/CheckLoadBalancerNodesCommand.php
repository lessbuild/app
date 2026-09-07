<?php

namespace App\Console\Commands;

use App\Models\LoadBalancerNode;
use App\Services\Runner;
use Illuminate\Console\Command;
use Throwable;

class CheckLoadBalancerNodesCommand extends Command
{
    protected $signature = 'buildpusher:load-balancers:check';

    protected $description = 'Check load-balancer upstream nodes from their edge servers';

    /**
     * Probe enabled upstream nodes from their balancer server and persist healthy or unhealthy status, including probe exceptions.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     * @return int SUCCESS after evaluating nodes, even when some probes report unhealthy.
     */
    public function handle(Runner $runner): int
    {
        LoadBalancerNode::query()->where('is_enabled', true)->with(['server', 'loadBalancer.server'])->each(function (LoadBalancerNode $node) use ($runner): void {
            try {
                $host = $node->server?->public_ip;
                if (! filter_var($host, FILTER_VALIDATE_IP)) {
                    throw new \RuntimeException('Node has no valid IP address.');
                }
                $url = escapeshellarg('http://'.$host.':'.$node->upstream_port.$node->loadBalancer->health_path);
                $result = $runner->server($node->loadBalancer->server)->create()->execute("curl --fail --silent --show-error --max-time 5 --output /dev/null {$url}");
                $node->update(['health_status' => $result->isSuccessful() ? 'healthy' : 'unhealthy', 'last_checked_at' => now()]);
            } catch (Throwable) {
                $node->update(['health_status' => 'unhealthy', 'last_checked_at' => now()]);
            }
        });

        return self::SUCCESS;
    }
}
