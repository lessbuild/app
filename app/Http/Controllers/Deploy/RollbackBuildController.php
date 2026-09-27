<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RollbackBuild;
use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RollbackBuildController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, Build $build, RollbackBuild $rollback): RedirectResponse
    {
        return to_route('deploy.builds.show', [$project, $rollback->handle($user, $build)->id]);
    }
}
