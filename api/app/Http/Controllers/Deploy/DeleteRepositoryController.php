<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\DeleteRepository;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteRepositoryController
{
    /**
     * Disconnect a repository. Its website keeps the release it's running.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Repository  $repository
     * @param  DeleteRepository  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Repository $repository, DeleteRepository $delete): RedirectResponse
    {
        $delete->handle($user, $repository);

        return to_route('deploy.repositories', $project)->with('status', __('Repository removed. The website keeps its current release.'));
    }
}
