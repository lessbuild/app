<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ApproveMigrations;
use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class ApproveMigrationsController
{
    /**
     * Approve the migrations a deploy stopped for and open the new deploy.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  ApproveMigrations  $approve
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Build $build, ApproveMigrations $approve): RedirectResponse
    {
        $deploy = $approve->handle($user, $build);

        return to_route('deploy.builds.show', [$project, $deploy->id])->with('status', __('Migrations approved. Deploying again.'));
    }
}
