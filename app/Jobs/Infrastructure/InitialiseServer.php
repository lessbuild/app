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

    /**
     * A new cloud server can take a few minutes to get its public IP, so this retries up to ten times.
     *
     * @var int
     */
    public int $tries = 10;

    /**
     * Seconds between tries.
     *
     * @var int
     */
    public int $backoff = 10;

    /**
     * Waits for a newly created cloud server's public IP and records its SSH host key.
     *
     * @param  int  $serverId  The server.
     * @param  string  $attempt  The initialisation token, so a job from an earlier attempt does nothing.
     */
    public function __construct(public readonly int $serverId, public readonly string $attempt) {}

    /**
     * Asks the provider for the server's addresses, pins its SSH host key, and moves it on to provisioning. Throws (and
     * so retries) while the IP isn't there yet.
     *
     * @param  ServerProviderResolver  $providers
     * @param  SshHostIdentity  $hostIdentity
     * @return void
     */
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

    /**
     * Marks the server failed at initialisation once retries run out.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $this->attempt()->whereIn('provisioning_status', [Server::STATUS_QUEUED, Server::STATUS_WAITING_FOR_IP])->update([
            'provisioning_status' => Server::STATUS_FAILED, 'provisioning_error' => Str::limit($exception->getMessage(), 2000),
            'provisioning_failure_phase' => Server::FAILURE_INITIALIZATION, 'initialization_token' => null,
        ]);
    }

    /**
     * The server, only while it's still on this initialisation attempt.
     *
     * @return Builder<Server>
     */
    private function attempt(): Builder
    {
        return Server::query()->whereKey($this->serverId)->where('initialization_token', $this->attempt);
    }
}
