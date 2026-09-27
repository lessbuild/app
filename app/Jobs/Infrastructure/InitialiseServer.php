<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Server;
use App\Services\Infrastructure\ServerProviderResolver;
use App\Services\Infrastructure\SshHostIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Waits for a new cloud server's public IP (retrying while the provider assigns it), pins its SSH host key, then hands over to
 * the provisioning script already running on it. Tied to one initialisation token, so a stale job does nothing.
 */
final class InitialiseServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 10;

    public int $backoff = 10;

    public function __construct(public readonly int $serverId, public readonly string $attempt) {}

    public function handle(ServerProviderResolver $providers, SshHostIdentity $hostIdentity): void
    {
        if ($this->attempt()->whereIn('provisioning_status', [Server::STATUS_QUEUED, Server::STATUS_WAITING_FOR_IP])
            ->update(['provisioning_status' => Server::STATUS_WAITING_FOR_IP, 'provisioning_error' => null, 'provisioning_failure_phase' => null]) === 0) {
            return;
        }
        $server = $this->attempt()->firstOrFail();
        if ($server->public_ip === null) {
            if ($server->identifier === null || $server->provider === null) {
                throw new RuntimeException('The cloud server or its provider is no longer available.');
            }
            $cloud = $providers->resolve($server->provider)->server($server->identifier);
            if ($cloud->publicIp === null) {
                throw new RuntimeException('The server’s public network isn’t ready yet.');
            }
            $identity = $hostIdentity->scan($cloud->publicIp, $server->ssh_port);
            $server->forceFill(['public_ip' => $cloud->publicIp, 'private_ip' => $cloud->privateIp, 'ssh_host_key' => $identity['known_host'], 'ssh_host_fingerprint' => $identity['fingerprint']])->save();
        }
        $this->attempt()->where('provisioning_status', Server::STATUS_WAITING_FOR_IP)
            ->update(['provisioning_status' => Server::STATUS_PROVISIONING, 'provisioning_failure_phase' => null, 'initialization_token' => null]);
    }

    public function failed(Throwable $exception): void
    {
        $this->attempt()->whereIn('provisioning_status', [Server::STATUS_QUEUED, Server::STATUS_WAITING_FOR_IP])->update([
            'provisioning_status' => Server::STATUS_FAILED, 'provisioning_error' => Str::limit($exception->getMessage(), 2000),
            'provisioning_failure_phase' => Server::FAILURE_INITIALIZATION, 'initialization_token' => null,
        ]);
    }

    /** @return Builder<Server> */
    private function attempt(): Builder
    {
        return Server::query()->whereKey($this->serverId)->where('initialization_token', $this->attempt);
    }
}
