<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ArchiveMonitor;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ArchiveMonitorController
{
    /**
     * Archive a monitor, keeping its history, if it hasn't changed since the page was opened.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Monitor  $monitor
     * @param  ArchiveMonitor  $archive
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Monitor $monitor, ArchiveMonitor $archive): JsonResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $archive->handle($monitor, $user, $version);

        return response()->json(['redirect' => route('monitoring.monitors', $project, false), 'message' => __(':monitor was archived. Its history is kept.', ['monitor' => $monitor->name])]);
    }
}
