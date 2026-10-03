<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Models\Monitor;
use App\Models\User;
use App\Support\Api\ApiMonitors;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListMonitorsController
{
    /**
     * List a project's monitors (`GET /api/v2/projects/{id}/monitors`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $projectId
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $projectId): JsonResponse
    {
        $project = ApiMonitors::project($request, $user, $projectId);

        return response()->json(['data' => Monitor::query()->whereIn('environment_id', $project->environments()->select('id'))->orderBy('name')->get()->map(ResourceJson::monitor(...))->values()]);
    }
}
