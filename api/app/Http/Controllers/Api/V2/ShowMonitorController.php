<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Models\User;
use App\Support\Api\ApiMonitors;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowMonitorController
{
    /**
     * Show a monitor (`GET /api/v2/projects/{id}/monitors/{monitor}`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $projectId
     * @param  int  $monitorId
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $projectId, int $monitorId): JsonResponse
    {
        $monitor = ApiMonitors::monitor($request, ApiMonitors::project($request, $user, $projectId), $monitorId);

        return response()->json(['data' => ResourceJson::monitor($monitor)]);
    }
}
