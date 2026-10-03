<?php

declare(strict_types=1);

namespace App\Contracts\Infrastructure;

/** A cloud provider that can snapshot a server's disk, for safety before risky changes. */
interface SnapshotsServers
{
    /**
     * Start a snapshot of the server and return the provider's ID for it (it completes in the background).
     *
     * @param  int|string  $identifier  the server's ID at the provider
     * @param  string  $name
     * @return string
     */
    public function snapshotServer(int|string $identifier, string $name): string;

    /**
     * Delete a snapshot; true when it's gone (or was already).
     *
     * @param  string  $snapshot
     * @return bool
     */
    public function deleteSnapshot(string $snapshot): bool;
}
