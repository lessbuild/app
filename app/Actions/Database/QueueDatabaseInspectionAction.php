<?php

namespace App\Actions\Database;

use App\Jobs\Database\CollectDatabaseSnapshotJob;
use App\Models\EnvironmentResource;

class QueueDatabaseInspectionAction
{
    /**
     * Queue one inspection for an already-authorized database resource.
     */
    public function handle(EnvironmentResource $resource): void
    {
        CollectDatabaseSnapshotJob::dispatch($resource->id);
    }
}
