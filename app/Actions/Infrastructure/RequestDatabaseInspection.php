<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\InspectDatabase;
use App\Models\DatabaseSnapshot;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Gate;

final class RequestDatabaseInspection
{
    /**
     * Queue a look at the database's size, connections and tables (by a person, or the daily run with no actor). Null if one is already waiting.
     *
     * @param  Website  $website
     * @param  User|null  $actor
     * @return DatabaseSnapshot|null
     */
    public function handle(Website $website, ?User $actor = null): ?DatabaseSnapshot
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('manageDatabase', $website);
        }
        if ($website->databaseSnapshots()->whereIn('status', ['queued', 'running'])->exists()) {
            return null;
        }
        $snapshot = new DatabaseSnapshot;
        $snapshot->forceFill(['website_id' => $website->id, 'requested_by' => $actor?->id, 'status' => 'queued'])->save();
        InspectDatabase::dispatch($snapshot->id);

        return $snapshot;
    }
}
