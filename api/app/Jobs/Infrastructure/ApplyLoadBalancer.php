<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\LoadBalancer;
use App\Models\Server;
use App\Services\Infrastructure\LoadBalancerConfiguration;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Writes the load balancer's Caddy site on its server and reloads Caddy. A newer change re-dispatches it; failures can be retried. */
final class ApplyLoadBalancer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One attempt; a failed apply is shown so someone can fix it and try again.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * How long writing and reloading the proxy configuration may take.
     *
     * @var int
     */
    public int $timeout = 120;

    /**
     * Create a new ApplyLoadBalancer instance.
     *
     * Writes a load balancer's configuration to its proxy server.
     *
     * @param  int  $loadBalancerId  The pending load balancer.
     */
    public function __construct(public readonly int $loadBalancerId) {}

    /**
     * Apply the configuration on the active proxy server and marks the load balancer active, or failed with Caddy's
     * error.
     *
     * @param  ServerShell  $shell
     * @param  LoadBalancerConfiguration  $configuration
     * @return void
     */
    public function handle(ServerShell $shell, LoadBalancerConfiguration $configuration): void
    {
        $balancer = LoadBalancer::query()->with(['server', 'nodes.server'])->whereKey($this->loadBalancerId)->where('status', 'pending')->first();
        if ($balancer === null) {
            return;
        }
        try {
            if ($balancer->server->provisioning_status !== Server::STATUS_ACTIVE) {
                throw new RuntimeException('The load balancer’s server isn’t active.');
            }
            $result = $shell->run($balancer->server, $configuration->apply($balancer));
            if (! $result->successful()) {
                throw new RuntimeException(trim($result->errorOutput ?: $result->output) ?: 'Caddy rejected the configuration.');
            }
        } catch (Throwable $exception) {
            $this->failed($exception);

            return;
        }
        LoadBalancer::query()->whereKey($balancer->id)->where('status', 'pending')->update(['status' => 'active', 'last_error' => null, 'applied_at' => now()->format('Y-m-d H:i:s.u')]);
    }

    /**
     * Mark a still-pending load balancer failed with the reason.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        LoadBalancer::query()->whereKey($this->loadBalancerId)->where('status', 'pending')->update(['status' => 'failed', 'last_error' => str($exception->getMessage())->limit(2000)->toString()]);
    }
}
