<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\PauseMonitor;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PauseMonitorController
{
    /**
     * Pause a monitor or turn it back on.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Monitor  $monitor
     * @param  PauseMonitor  $pause
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Monitor $monitor, PauseMonitor $pause): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:0']]);
        $monitor = $pause->handle($project, $user, $monitor, (bool) $data['enabled'], (int) $data['version']);

        return response()->json(['message' => $monitor->enabled ? __('Monitor on. It’s checked straight away.') : __('Monitor paused.')]);
    }
}
