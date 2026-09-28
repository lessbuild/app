<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\CancelBuild;
use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class CancelBuildController
{
    /**
     * Cancels a deploy that hasn't finished.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  CancelBuild  $cancel
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Build $build, CancelBuild $cancel): RedirectResponse
    {
        $cancel->handle($user, $build);

        return to_route('deploy.builds.show', [$project, $build->id])->with('status', __('Deploy canceled.'));
    }
}
