<?php

namespace App\Modules\Deployer\Actions\Database;

use App\Modules\Deployer\Jobs\Database\ManageDatabaseUserJob;
use App\Modules\Deployer\Models\DatabaseOperationRun;
use App\Modules\Deployer\Models\DatabaseUser;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueueDatabaseUserRemovalAction
{
    /**
     * Queue remote removal for an already-authorized database credential.
     */
    public function handle(DatabaseUser $databaseUser): void
    {
        [$operationRun, $shouldDispatch] = DB::connection('deployer')->transaction(function () use ($databaseUser): array {
            $lockedUser = DatabaseUser::query()->lockForUpdate()->findOrFail($databaseUser->getKey());
            $existingRun = DatabaseOperationRun::query()
                ->where('environment_resource_id', $lockedUser->environment_resource_id)
                ->where('subject_id', $lockedUser->getKey())
                ->where('operation', 'user_remove')
                ->whereIn('status', [DatabaseOperationRun::QUEUED, DatabaseOperationRun::RUNNING])
                ->latest('id')
                ->first();

            if ($existingRun !== null) {
                return [$existingRun, false];
            }

            return [DatabaseOperationRun::query()->create([
                'environment_resource_id' => $lockedUser->environment_resource_id,
                'operation' => 'user_remove',
                'subject_id' => $lockedUser->getKey(),
                'status' => DatabaseOperationRun::QUEUED,
            ]), true];
        });

        if (! $shouldDispatch) {
            return;
        }

        try {
            ManageDatabaseUserJob::dispatch((int) $databaseUser->getKey(), 'remove', (int) $operationRun->getKey());
        } catch (Throwable $exception) {
            $operationRun->markFailed();

            throw $exception;
        }
    }
}
