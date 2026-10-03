<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Actions\Monitoring\SaveMonitor;
use App\Http\Requests\Monitoring\MonitorRequest;
use App\Models\User;
use App\Support\Api\ApiMonitors;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreMonitorController
{
    /**
     * Create a monitor in one of the project's environments (`POST /api/v2/projects/{id}/monitors`), with the same
     * fields as the monitor form.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $projectId
     * @param  SaveMonitor  $save
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $projectId, SaveMonitor $save): JsonResponse
    {
        $project = ApiMonitors::project($request, $user, $projectId);
        // Alerts on opening and recovery are on unless the request turns them off.
        $request->merge(['opened' => $request->boolean('opened', true), 'recovered' => $request->boolean('recovered', true)]);
        $monitor = $save->handle($project, $user, app(MonitorRequest::class)->validated());

        return response()->json(['data' => ResourceJson::monitor($monitor)], 201);
    }
}
