<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RemoveDatabaseUser;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteDatabaseUserController
{
    /**
     * Start removing an extra database user.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  string  $databaseUser
     * @param  RemoveDatabaseUser  $remove
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, string $databaseUser, RemoveDatabaseUser $remove): JsonResponse
    {
        $remove->handle($website->databaseUsers()->findOrFail((int) $databaseUser), $user);

        return response()->json(['redirect' => route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'database'], false), 'message' => __('Removing the database user.')]);
    }
}
