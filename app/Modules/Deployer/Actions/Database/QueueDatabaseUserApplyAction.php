<?php

namespace App\Modules\Deployer\Actions\Database;

use App\Modules\Deployer\Jobs\Database\ManageDatabaseUserJob;
use App\Modules\Deployer\Models\DatabaseOperationRun;
use App\Modules\Deployer\Models\DatabaseUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class QueueDatabaseUserApplyAction
{
    /**
     * Retry applying the stored encrypted credential without returning or exposing its password.
     */
    public function handle(DatabaseUser $databaseUser): bool
    {
        [$operationRun, $shouldDispatch] = DB::connection('deployer')->transaction(function () use ($databaseUser): array {
            $lockedUser = DatabaseUser::query()->lockForUpdate()->findOrFail($databaseUser->getKey());

            if ($lockedUser->applied_at !== null) {
                throw ValidationException::withMessages([
                    'database_user' => __('This database credential has already been applied.'),
                ]);
            }

            $existingRun = DatabaseOperationRun::query()
                ->where('environment_resource_id', $lockedUser->environment_resource_id)
                ->where('subject_id', $lockedUser->getKey())
                ->where('operation', 'user_apply')
                ->whereIn('status', [DatabaseOperationRun::QUEUED, DatabaseOperationRun::RUNNING])
                ->latest('id')
                ->first();

            if ($existingRun !== null) {
                return [$existingRun, false];
            }

            return [DatabaseOperationRun::query()->create([
                'environment_resource_id' => $lockedUser->environment_resource_id,
                'operation' => 'user_apply',
                'subject_id' => $lockedUser->getKey(),
                'status' => DatabaseOperationRun::QUEUED,
            ]), true];
        });

        if (! $shouldDispatch) {
            return false;
        }

        try {
            ManageDatabaseUserJob::dispatch((int) $databaseUser->getKey(), 'apply', (int) $operationRun->getKey());
        } catch (Throwable $exception) {
            $operationRun->markFailed();

            throw $exception;
        }

        return true;
    }
}
