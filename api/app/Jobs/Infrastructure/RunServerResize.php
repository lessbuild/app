<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Contracts\Infrastructure\ResizableServerProvider;
use App\Models\Server;
use App\Services\Infrastructure\ServerProviderResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Resizes a cloud server: powers it off, changes its size (optionally growing the disk), powers it back on, then reads
 * the new size and address back. The server is marked `resizing` while this runs and `active` when it's done or fails.
 */
final class RunServerResize implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One attempt: a resize powers the server off and on, so it's not retried blindly.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * A resize (power off, change, power on) can take a good while.
     *
     * @var int
     */
    public int $timeout = 1800;

    /**
     * Create a new RunServerResize instance.
     *
     * @param  int  $serverId  The server.
     * @param  string  $size  The new size slug.
     * @param  bool  $resizeDisk  Also grow the disk (permanent).
     */
    public function __construct(public readonly int $serverId, public readonly string $size, public readonly bool $resizeDisk) {}

    /**
     * Never resize the same server twice at once (a re-reserved long job included); a second one is dropped.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('server-resize:'.$this->serverId))->dontRelease()->expireAfter(2100)];
    }

    /**
     * Power off, resize, power on, and record the new size; on any failure make sure it's powered back on and active.
     *
     * @param  ServerProviderResolver  $providers
     * @return void
     */
    public function handle(ServerProviderResolver $providers): void
    {
        $server = Server::query()->with('provider')->find($this->serverId);
        if ($server === null || $server->provider === null || $server->identifier === null) {
            return;
        }
        $client = $providers->resolve($server->provider);
        if (! $client instanceof ResizableServerProvider) {
            $server->forceFill(['provisioning_status' => Server::STATUS_ACTIVE])->save();

            return;
        }
        $identifier = $server->identifier;

        try {
            $client->powerOffServer($identifier);
            $client->resizeServer($identifier, $this->size, $this->resizeDisk);
            $client->powerOnServer($identifier);
            $data = $client->server($identifier);
            $server->forceFill([
                'size' => $data->size,
                'public_ip' => $data->publicIp ?? $server->public_ip,
                'provisioning_status' => Server::STATUS_ACTIVE,
            ])->save();
        } catch (Throwable $exception) {
            // Whatever went wrong, don't leave the server switched off.
            try {
                $client->powerOnServer($identifier);
            } catch (Throwable) {
                // Nothing more we can do here; the failure below is what's reported.
            }
            $server->forceFill(['provisioning_status' => Server::STATUS_ACTIVE])->save();

            throw $exception;
        }
    }

    /**
     * If the job is dropped before finishing, leave the server active rather than stuck on resizing.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Server::query()->whereKey($this->serverId)->where('provisioning_status', Server::STATUS_RESIZING)
            ->update(['provisioning_status' => Server::STATUS_ACTIVE]);
    }
}
