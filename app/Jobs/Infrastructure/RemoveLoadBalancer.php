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

    /**
     * One attempt; a failed removal is shown so someone can try again.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * How long removing the proxy configuration may take.
     *
     * @var int
     */
    public int $timeout = 120;

    /**
     * Removes a load balancer's proxy configuration from its server and then the load balancer.
     *
     * @param  int  $loadBalancerId  The load balancer being removed.
     */
    public function __construct(public readonly int $loadBalancerId) {}

    /**
     * Removes the configuration and deletes the record once the server confirms.
     *
     * @param  ServerShell  $shell
     * @param  LoadBalancerConfiguration  $configuration
     * @return void
     */
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

    /**
     * Marks the removal failed with the server's error, keeping the record so it can be retried.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        LoadBalancer::query()->whereKey($this->loadBalancerId)->where('status', 'removing')
            ->update(['status' => 'removal_failed', 'last_error' => 'The proxy configuration couldn’t be removed from the server: '.str($exception->getMessage())->limit(1000)]);
    }
}
