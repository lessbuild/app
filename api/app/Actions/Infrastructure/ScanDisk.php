<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Exceptions\AccountRuleViolation;
use App\Jobs\Infrastructure\ScanServerDisk;
use App\Models\Server;
use App\Models\ServerDiskScan;
use App\Models\User;
use App\Services\Infrastructure\DiskCleanup;
use Illuminate\Support\Facades\Gate;

final class ScanDisk
{
    /**
     * Queue a scan of what's taking clearable space on the server, clearing one category first when asked. The person
     * must be allowed to run commands on the server.
     *
     * @param  User  $actor
     * @param  Server  $server
     * @param  string|null  $clean  a category of DiskCleanup::CATEGORIES to clear
     * @return ServerDiskScan
     */
    public function handle(User $actor, Server $server, ?string $clean): ServerDiskScan
    {
        Gate::forUser($actor)->authorize('runCommands', $server);
        if ($server->provisioning_status !== Server::STATUS_ACTIVE) {
            throw new AccountRuleViolation('server', __('The server needs to finish setting up first.'));
        }
        if ($clean !== null && ! array_key_exists($clean, DiskCleanup::CATEGORIES)) {
            throw new AccountRuleViolation('category', __('Choose what to clear.'));
        }
        $scan = ServerDiskScan::query()->where('server_id', $server->id)->first() ?? new ServerDiskScan;
        $scan->forceFill(['server_id' => $server->id, 'status' => 'queued', 'error' => null])->save();
        ScanServerDisk::dispatch($server->id, $clean)->afterCommit();

        return $scan;
    }
}
