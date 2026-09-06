<?php

namespace App\Jobs;

use App\Models\LoadBalancer;
use App\Models\Server;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class ApplyLoadBalancerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $loadBalancerId) {}

    public function uniqueId(): string
    {
        return (string) $this->loadBalancerId;
    }

    public function handle(Runner $runner): void
    {
        $balancer = LoadBalancer::query()->with(['server', 'nodes.server'])->find($this->loadBalancerId);
        if (! $balancer?->server || $balancer->server->provisioning_status !== Server::STATUS_ACTIVE) {
            return;
        }
        try {
            $upstreams = $balancer->nodes->filter(fn ($node) => $node->is_enabled && $node->server?->provisioning_status === Server::STATUS_ACTIVE)
                ->flatMap(fn ($node) => array_fill(0, max(1, min(10, $node->weight)), 'http://'.$node->server->public_ip.':'.$node->upstream_port))->values();
            $hostname = strtolower($balancer->hostname);
            if (! preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', $hostname)) {
                throw new RuntimeException('Unsafe load-balancer hostname.');
            }
            $path = $balancer->health_path;
            if (! preg_match('#\A/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*\z#D', $path)) {
                throw new RuntimeException('Unsafe health path.');
            }
            $block = $upstreams->isEmpty()
                ? $hostname." {\n    respond \"No healthy application nodes\" 503\n}\n"
                : $hostname." {\n    reverse_proxy ".implode(' ', $upstreams->all())." {\n        lb_policy least_conn\n        health_uri {$path}\n        health_interval 10s\n        health_timeout 3s\n        fail_duration 30s\n        max_fails 2\n    }\n}\n";
            $encoded = escapeshellarg(base64_encode($block));
            $file = escapeshellarg('/etc/caddy/websites/ha-'.$balancer->id.'.conf');
            $script = "set -e\nprintf '%s' {$encoded} | base64 --decode > {$file}\ncaddy fmt --overwrite {$file}\ncaddy validate --config /etc/caddy/Caddyfile\nsystemctl reload caddy";
            $result = $runner->server($balancer->server)->create()->execute($script);
            if (! $result->isSuccessful()) {
                throw new RuntimeException(trim($result->getErrorOutput()) ?: 'Caddy rejected the load-balancer configuration.');
            }
            $balancer->update(['status' => 'active', 'last_error' => null, 'applied_at' => now()]);
        } catch (Throwable $exception) {
            $balancer->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }
}
