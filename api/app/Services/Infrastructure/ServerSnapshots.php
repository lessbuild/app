<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Contracts\Infrastructure\SnapshotsServers;
use App\Models\Server;
use App\Models\ServerSnapshot;
use Illuminate\Support\Str;
use Throwable;

/**
 * Takes a provider snapshot before a risky change when the server asks for it, and keeps the three newest.
 */
final class ServerSnapshots
{
    /**
     * How many snapshots are kept per server.
     *
     * @var int
     */
    public const KEEP = 3;

    /**
     * Create a new ServerSnapshots instance.
     *
     * @param  ServerProviderResolver  $providers  Reaches the server's provider.
     */
    public function __construct(private readonly ServerProviderResolver $providers) {}

    /**
     * Snapshot the server before a change, when it's set to (or when forced), then delete the oldest beyond three. A
     * failed snapshot is recorded and the change goes ahead. Returns null when no snapshot was wanted or possible.
     *
     * @param  Server  $server
     * @param  string  $reason
     * @param  bool  $force  take one even when the server isn't set to
     * @return ServerSnapshot|null
     */
    public function before(Server $server, string $reason, bool $force = false): ?ServerSnapshot
    {
        $server->loadMissing('provider');
        if ((! $server->snapshot_before_changes && ! $force) || $server->provider === null || $server->identifier === null) {
            return null;
        }
        try {
            $client = $this->providers->resolve($server->provider);
        } catch (Throwable) {
            return null;
        }
        if (! $client instanceof SnapshotsServers) {
            return null;
        }
        $snapshot = new ServerSnapshot;
        $snapshot->server_id = $server->id;
        $snapshot->reason = mb_substr($reason, 0, 120);
        try {
            $snapshot->forceFill(['provider_snapshot' => $client->snapshotServer($server->identifier, 'buildpusher-'.Str::slug($server->name).'-'.now()->format('Ymd-His')), 'status' => 'taken'])->save();
        } catch (Throwable $exception) {
            $snapshot->forceFill(['status' => 'failed', 'error' => Str::limit($exception->getMessage(), 480)])->save();

            return $snapshot;
        }
        ServerSnapshot::query()->where('server_id', $server->id)->where('status', 'taken')->latest('id')->skip(self::KEEP)->take(50)->get()
            ->each(function (ServerSnapshot $old) use ($client): void {
                try {
                    if ($old->provider_snapshot !== null && $client->deleteSnapshot($old->provider_snapshot)) {
                        $old->forceFill(['status' => 'deleted'])->save();
                    }
                } catch (Throwable) {
                    // Left for the next time.
                }
            });

        return $snapshot;
    }
}
