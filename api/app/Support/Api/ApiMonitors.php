<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Models\Environment;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;

/** Finds the project and monitor a resources-API monitor request is about, and lets the monitor form's rules read them. */
final class ApiMonitors
{
    /**
     * Get one of the token's projects with Monitoring that the person can see, and bind it for the monitor rules.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $projectId
     * @return Project
     */
    public static function project(Request $request, User $user, string $projectId): Project
    {
        $project = Project::query()->where('account_id', ResourceJson::account($request)->id)->findOrFail($projectId);
        abort_unless($user->can('view', $project) && $project->hasService('monitoring'), 404);
        $request->route()?->setParameter('project', $project);

        return $project;
    }

    /**
     * Get a monitor in the project, and bind it for the monitor rules.
     *
     * @param  Request  $request
     * @param  Project  $project
     * @param  int  $monitorId
     * @return Monitor
     */
    public static function monitor(Request $request, Project $project, int $monitorId): Monitor
    {
        $monitor = Monitor::query()->whereIn('environment_id', Environment::query()->where('project_id', $project->id)->select('id'))->findOrFail($monitorId);
        $request->route()?->setParameter('monitor', $monitor);

        return $monitor;
    }
}
