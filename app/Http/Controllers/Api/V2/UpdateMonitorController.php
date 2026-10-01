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

final class UpdateMonitorController
{
    /**
     * Change a monitor (`PUT /api/v2/projects/{id}/monitors/{monitor}`). Send the version you read to refuse changes
     * made in between; without one the current version is used.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $projectId
     * @param  int  $monitorId
     * @param  SaveMonitor  $save
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $projectId, int $monitorId, SaveMonitor $save): JsonResponse
    {
        $project = ApiMonitors::project($request, $user, $projectId);
        $monitor = ApiMonitors::monitor($request, $project, $monitorId);
        // Alerts on opening and recovery are on unless the request turns them off.
        $request->merge(['opened' => $request->boolean('opened', true), 'recovered' => $request->boolean('recovered', true)]);
        if (! $request->has('version')) {
            $request->merge(['version' => $monitor->state_version]);
        }
        $monitor = $save->handle($project, $user, app(MonitorRequest::class)->validated(), $monitor);

        return response()->json(['data' => ResourceJson::monitor($monitor)]);
    }
}
