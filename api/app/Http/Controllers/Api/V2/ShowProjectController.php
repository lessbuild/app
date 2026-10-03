<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Models\Project;
use App\Models\User;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowProjectController
{
    /**
     * Show one of the token's projects (`GET /api/v2/projects/{id}`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $projectId
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $projectId): JsonResponse
    {
        $project = Project::query()->where('account_id', ResourceJson::account($request)->id)->findOrFail($projectId);
        abort_unless($user->can('view', $project), 404);

        return response()->json(['data' => ResourceJson::project($project)]);
    }
}
