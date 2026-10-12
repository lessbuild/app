<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Project;
use App\Models\Repository;
use App\Services\Deploy\GitRepositories;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/repositories/{repository}/branches`. */
final class ListRepositoryBranchesController
{
    /**
     * Return a connected repository's branches through its own Git provider, to pick one when deploying a version or
     * booking a deploy.
     *
     * @param  Project  $project
     * @param  Repository  $repository
     * @param  GitRepositories  $repositories
     * @return JsonResponse
     */
    public function __invoke(Project $project, Repository $repository, GitRepositories $repositories): JsonResponse
    {
        $provider = $repository->provider;

        return response()->json($provider === null
            ? ['branches' => [], 'error' => __('The branches couldn’t be listed. Type the branch name instead.')]
            : $repositories->branchesOrReason($provider, $repository->url));
    }
}
