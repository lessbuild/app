<?php

namespace App\Modules\Deployer\Actions\Database;

use App\Modules\Deployer\Jobs\Database\CollectDatabaseSnapshotJob;
use App\Modules\Deployer\Models\DatabaseOperationRun;
use App\Modules\Deployer\Models\EnvironmentResource;
use Throwable;

class QueueDatabaseInspectionAction
{
    /**
     * Queue one inspection for an already-authorized database resource.
     */
    public function handle(EnvironmentResource $resource): void
    {
        $operationRun = DatabaseOperationRun::query()->create([
            'environment_resource_id' => $resource->getKey(),
            'operation' => 'inspection',
            'status' => DatabaseOperationRun::QUEUED,
        ]);

        try {
            CollectDatabaseSnapshotJob::dispatch((int) $resource->getKey(), (int) $operationRun->getKey());
        } catch (Throwable $exception) {
            $operationRun->markFailed();

            throw $exception;
        }
    }
}
