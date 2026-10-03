<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Models\Project;
use App\Models\User;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListProjectsController
{
    /**
     * List the token's projects (`GET /api/v2/projects`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $projects = Project::query()->where('account_id', ResourceJson::account($request)->id)->with(['enabledServices', 'environments'])->orderBy('name')->get()
            ->filter(fn (Project $project): bool => $user->can('view', $project));

        return response()->json(['data' => $projects->map(ResourceJson::project(...))->values()]);
    }
}
