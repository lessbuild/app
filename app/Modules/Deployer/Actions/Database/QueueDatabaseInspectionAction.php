<?php

namespace App\Modules\Deployer\Actions\Database;

use App\Modules\Deployer\Jobs\Database\CollectDatabaseSnapshotJob;
use App\Modules\Deployer\Models\EnvironmentResource;

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
