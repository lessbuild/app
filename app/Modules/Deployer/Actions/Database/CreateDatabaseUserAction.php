<?php

namespace App\Modules\Deployer\Actions\Database;

use App\Modules\Deployer\Data\DatabaseUserCreationResult;
use App\Modules\Deployer\Jobs\Database\ManageDatabaseUserJob;
use App\Modules\Deployer\Models\DatabaseOperationRun;
use App\Modules\Deployer\Models\EnvironmentResource;
use App\Modules\Deployer\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CreateDatabaseUserAction
{
    /**
     * Persist an encrypted database credential and queue its remote application.
     *
     * @param  array{username: string, privilege: string, expires_in_days?: int|string|null}  $attributes  Validated credential attributes.
     */
    public function handle(EnvironmentResource $resource, User $actor, array $attributes): DatabaseUserCreationResult
    {
        $password = Str::password(32);
        [$user, $operationRun] = DB::connection('deployer')->transaction(function () use ($resource, $actor, $attributes, $password): array {
            $user = $resource->databaseUsers()->create([
                'created_by' => $actor->id,
                'username' => $attributes['username'],
                'password' => $password,
                'privilege' => $attributes['privilege'],
                'expires_at' => filled($attributes['expires_in_days'] ?? null)
                    ? now()->addDays((int) $attributes['expires_in_days'])
                    : null,
            ]);
            $operationRun = DatabaseOperationRun::query()->create([
                'environment_resource_id' => $resource->getKey(),
                'operation' => 'user_apply',
                'subject_id' => $user->getKey(),
                'status' => DatabaseOperationRun::QUEUED,
            ]);

            return [$user, $operationRun];
        });

        try {
            ManageDatabaseUserJob::dispatch((int) $user->getKey(), 'apply', (int) $operationRun->getKey());
        } catch (Throwable $exception) {
            $operationRun->markFailed();

            throw $exception;
        }

        return new DatabaseUserCreationResult($user, $password);
    }
}
