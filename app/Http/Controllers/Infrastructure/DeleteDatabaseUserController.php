<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RemoveDatabaseUser;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

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
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, string $databaseUser, RemoveDatabaseUser $remove): RedirectResponse
    {
        $remove->handle($website->databaseUsers()->findOrFail((int) $databaseUser), $user);

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'database'])->with('status', __('Removing the database user.'));
    }
}
