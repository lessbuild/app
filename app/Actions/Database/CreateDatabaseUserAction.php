<?php

namespace App\Actions\Database;

use App\Data\DatabaseUserCreationResult;
use App\Jobs\Database\ManageDatabaseUserJob;
use App\Models\EnvironmentResource;
use App\Models\User;
use Illuminate\Support\Str;

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
        $user = $resource->databaseUsers()->create([
            'created_by' => $actor->id,
            'username' => $attributes['username'],
            'password' => $password,
            'privilege' => $attributes['privilege'],
            'expires_at' => filled($attributes['expires_in_days'] ?? null)
                ? now()->addDays((int) $attributes['expires_in_days'])
                : null,
        ]);
        ManageDatabaseUserJob::dispatch($user->id, 'apply');

        return new DatabaseUserCreationResult($user, $password);
    }
}
