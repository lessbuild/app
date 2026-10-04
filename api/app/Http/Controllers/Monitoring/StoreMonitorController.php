<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveMonitor;
use App\Http\Requests\Monitoring\MonitorRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreMonitorController
{
    /**
     * Create a monitor.
     *
     * @param  MonitorRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveMonitor  $save
     * @return JsonResponse
     */
    public function __invoke(MonitorRequest $request, #[CurrentUser] User $user, Project $project, SaveMonitor $save): JsonResponse
    {
        $monitor = $save->handle($project, $user, $request->validated());

        return response()->json(['redirect' => route('monitoring.monitors.show', [$project, $monitor->id], false), 'message' => __('Monitor created.')]);
    }
}
