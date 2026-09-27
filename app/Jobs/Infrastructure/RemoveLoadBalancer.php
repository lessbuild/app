<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\LoadBalancer;
use App\Services\Infrastructure\LoadBalancerConfiguration;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Removes the Caddy site from the proxy server, then the load balancer. If the server can't be reached it stays, marked for a retry. */
final class RemoveLoadBalancer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $loadBalancerId) {}

    public function handle(ServerShell $shell, LoadBalancerConfiguration $configuration): void
    {
        $balancer = LoadBalancer::query()->with('server')->whereKey($this->loadBalancerId)->where('status', 'removing')->first();
        if ($balancer === null) {
            return;
        }
        try {
            $result = $shell->run($balancer->server, $configuration->remove($balancer->id));
        } catch (Throwable $exception) {
            $this->failed($exception);

            return;
        }
        if (! $result->successful()) {
            $this->failed(new RuntimeException(trim($result->errorOutput ?: $result->output)));

            return;
        }
        $balancer->delete();
    }

    public function failed(Throwable $exception): void
    {
        LoadBalancer::query()->whereKey($this->loadBalancerId)->where('status', 'removing')
            ->update(['status' => 'removal_failed', 'last_error' => 'The proxy configuration couldn’t be removed from the server: '.str($exception->getMessage())->limit(1000)]);
    }
}
