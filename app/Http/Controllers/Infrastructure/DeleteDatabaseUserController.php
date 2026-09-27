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
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, string $databaseUser, RemoveDatabaseUser $remove): RedirectResponse
    {
        $remove->handle($website->databaseUsers()->findOrFail((int) $databaseUser), $user);

        return to_route('infrastructure.websites.show', [$project, $website->id])->withFragment('database')->with('status', __('Removing the database user.'));
    }
}
