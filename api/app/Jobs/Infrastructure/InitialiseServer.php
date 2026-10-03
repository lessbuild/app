<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Data\Infrastructure\CloudServerData;
use App\Models\Server;
use App\Services\Infrastructure\ServerProviderResolver;
use App\Services\Infrastructure\SshHostIdentity;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Waits for a new cloud server to be running with a public IP and answering SSH (retrying for up to twenty minutes), pins its
 * SSH host key, then hands over to the provisioning script already running on it. Tied to one initialisation token, so a
 * stale job does nothing.
 */
final class InitialiseServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds between tries.
     *
     * @var int
     */
    public int $backoff = 15;

    /**
     * Create a new InitialiseServer instance.
     *
     * Waits for a newly created cloud server's public IP and records its SSH host key.
     *
     * @param  int  $serverId  The server.
     * @param  string  $attempt  The initialisation token, so a job from an earlier attempt does nothing.
     */
    public function __construct(public readonly int $serverId, public readonly string $attempt) {}

    /**
     * Keep retrying for twenty minutes: a new server can take several minutes to boot, get its address and start SSH.
     *
     * @return DateTimeInterface
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(20);
    }

    /**
     * Ask the provider for the server's addresses, pin its SSH host key, and move it on to provisioning. Throws (and so
     * retries) while the server isn't ready, noting why on the server so its page can say what it's waiting for.
     *
     * @param  ServerProviderResolver  $providers
     * @param  SshHostIdentity  $hostIdentity
     * @return void
     */
    public function handle(ServerProviderResolver $providers, SshHostIdentity $hostIdentity): void
    {
        if ($this->attempt()->whereIn('provisioning_status', [Server::STATUS_QUEUED, Server::STATUS_WAITING_FOR_IP])
            ->update(['provisioning_status' => Server::STATUS_WAITING_FOR_IP, 'provisioning_failure_phase' => null]) === 0) {
            return;
        }
        try {
            $server = $this->attempt()->firstOrFail();
            if ($server->public_ip === null) {
                if ($server->identifier === null || $server->provider === null) {
                    throw new RuntimeException('The cloud server or its provider is no longer available.');
                }
                $cloud = $providers->resolve($server->provider)->server($server->identifier);
                $ip = $this->usableIp($cloud);
                $identity = $hostIdentity->scan($ip, $server->ssh_port);
                $server->forceFill(['public_ip' => $ip, 'private_ip' => $cloud->privateIp, 'ssh_host_key' => $identity['known_host'], 'ssh_host_fingerprint' => $identity['fingerprint']])->save();
            }
        } catch (Throwable $exception) {
            $this->attempt()->where('provisioning_status', Server::STATUS_WAITING_FOR_IP)->update(['provisioning_error' => Str::limit($exception->getMessage(), 2000)]);

            throw $exception;
        }
        $this->attempt()->where('provisioning_status', Server::STATUS_WAITING_FOR_IP)
            ->update(['provisioning_status' => Server::STATUS_PROVISIONING, 'provisioning_error' => null, 'provisioning_failure_phase' => null, 'initialization_token' => null]);
    }

    /**
     * Mark the server failed at initialisation once retries run out.
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
     * Get the server's public IP once the provider reports it running with a real address. Providers can report a
     * placeholder such as 0.0.0.0 while booting, and scanning that would reach this machine instead of the new server.
     *
     * @param  CloudServerData  $cloud
     * @return string
     */
    private function usableIp(CloudServerData $cloud): string
    {
        if ($cloud->readiness === CloudServerData::READINESS_NOT_READY) {
            throw new RuntimeException('The provider is still starting the server.');
        }
        if ($cloud->publicIp === null || filter_var($cloud->publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('The provider hasn’t given the server a public IP yet.');
        }

        return $cloud->publicIp;
    }

    /**
     * Query the server, only while it's still on this initialisation attempt.
     *
     * @return Builder<Server>
     */
    private function attempt(): Builder
    {
        return Server::query()->whereKey($this->serverId)->where('initialization_token', $this->attempt);
    }
}
