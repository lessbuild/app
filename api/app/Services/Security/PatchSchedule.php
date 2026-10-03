<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Jobs\Security\PatchServer;
use App\Models\Server;
use Carbon\CarbonImmutable;

/** Starts servers' weekly update windows. */
final class PatchSchedule
{
    /**
     * Queue updates for every active server whose window is this hour (UTC) and that hasn't been updated in the last
     * 20 hours. Returns how many were queued.
     *
     * @return int
     */
    public function runDue(): int
    {
        $now = CarbonImmutable::now('UTC');
        $queued = 0;
        Server::query()->where('provisioning_status', Server::STATUS_ACTIVE)->where('patch_day', $now->dayOfWeek)->where('patch_hour', $now->hour)
            ->where(fn ($query) => $query->whereNull('last_patched_at')->orWhere('last_patched_at', '<', $now->subHours(20)))
            ->each(function (Server $server) use (&$queued): void {
                PatchServer::dispatch($server->id, $server->patch_reboot);
                $queued++;
            });

        return $queued;
    }
}
