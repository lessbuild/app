<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Project;
use App\Models\Provider;
use App\Services\Deploy\GitRepositories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/app/projects/{project}/deploy/repositories/branches?provider=&url=`. */
final class ListBranchesController
{
    /**
     * Return the branches of a repository one of the account's Git providers can reach, to pick the branch when
     * connecting it or changing its settings. When they can't be listed, say so; the branch can still be typed in.
     *
     * @param  Request  $request
     * @param  Project  $project
     * @param  GitRepositories  $repositories
     * @return JsonResponse
     */
    public function __invoke(Request $request, Project $project, GitRepositories $repositories): JsonResponse
    {
        $provider = Provider::query()->where('account_id', $project->account_id)->findOrFail($request->integer('provider'));

        return response()->json($repositories->branchesOrReason($provider, $request->string('url')->toString()));
    }
}
