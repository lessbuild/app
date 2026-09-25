<?php

namespace App\Modules\Deployer\Jobs;

use App\Modules\Deployer\Models\LoadBalancer;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class RemoveLoadBalancerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 900;

    public int $tries = 18;

    public int $maxExceptions = 3;

    public int $timeout = 75;

    public array $backoff = [10, 30, 60];

    /**
     * Retain the identifiers needed to remove routing before deleting its durable source record.
     *
     * @param  int  $serverId  Managed server identifier retained for remote work when the job runs.
     * @param  int  $loadBalancerId  Persisted load-balancer identifier used to locate its routing configuration.
     */
    public function __construct(public readonly int $serverId, public readonly int $loadBalancerId) {}

    /** Coalesce direct and scheduled re-dispatches for the same persisted balancer. */
    public function uniqueId(): string
    {
        return (string) $this->loadBalancerId;
    }

    /** @return array<int, WithoutOverlapping> Serialize apply and removal commands for one remote route. */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('load-balancer:'.$this->loadBalancerId))
                ->shared()
                ->releaseAfter(5)
                ->expireAfter(90),
        ];
    }

    /**
     * Remove the balancer's Caddy file, validate and reload; delete the source record only after remote success.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     * @return void Skip stale jobs; otherwise require successful remote removal and reload.
     *
     * @throws RuntimeException If the remote removal, validation or reload exits unsuccessfully.
     */
    public function handle(Runner $runner): void
    {
        $loadBalancer = LoadBalancer::query()
            ->whereKey($this->loadBalancerId)
            ->where('server_id', $this->serverId)
            ->where('status', 'removing')
            ->first();

        if ($loadBalancer === null) {
            return;
        }

        $server = Server::find($this->serverId);
        if (! $server) {
            throw new RuntimeException('The managed load-balancer server is unavailable for remote cleanup.');
        }
        $file = escapeshellarg('/etc/caddy/websites/ha-'.$this->loadBalancerId.'.conf');
        $result = $runner->server($server)->create()->execute("set -e\nrm -f -- {$file}\ncaddy validate --config /etc/caddy/Caddyfile\nsystemctl reload caddy");
        if (! $result->isSuccessful()) {
            throw new RuntimeException("Unable to remove load-balancer configuration {$this->loadBalancerId} from server {$this->serverId}.");
        }

        LoadBalancer::query()
            ->whereKey($this->loadBalancerId)
            ->where('server_id', $this->serverId)
            ->where('status', 'removing')
            ->delete();
    }

    /** Keep failed cleanup visible and retryable without persisting provider or SSH output. */
    public function failed(Throwable $exception): void
    {
        LoadBalancer::query()
            ->whereKey($this->loadBalancerId)
            ->where('server_id', $this->serverId)
            ->where('status', 'removing')
            ->update([
                'status' => 'removal_failed',
                'last_error' => 'Remote load-balancer cleanup failed. Retry removal from Deployer.',
                'updated_at' => now(),
            ]);
    }
}
