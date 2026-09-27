<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/v1/projects`: projects with Deploy the token can use, each with its environments. */
final class ListProjectsController
{
    /**
     * Returns the projects the token can deploy, oldest first.
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, #[TokenAccount] Account $account, DeployApiQuery $query): JsonResponse
    {
        $page = $query->page($query->projects($user, $account), $request->query('limit'), $request->query('cursor'), 'asc', 500);

        return response()->json(array_filter(['data' => collect($page['items'])->map(fn (Project $project): array => $query->projectData($project))->values(), 'meta' => $page['meta']], fn (mixed $value): bool => $value !== null));
    }
}
