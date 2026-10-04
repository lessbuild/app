<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Queries\Infrastructure\ServerPageQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Infrastructure\ServerLogs;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowServerController
{
    /**
     * Show a server: provisioning, resources, alerts, diagnostics, database recovery and replicas, cron jobs,
     * processes, firewall, services, logs (`?log=` picks one) and settings.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ProjectOverviewQuery  $overview
     * @param  ServerPageQuery  $page
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ProjectOverviewQuery $overview, ServerPageQuery $page): JsonResponse
    {
        $log = $request->query('log');
        $logType = is_string($log) && array_key_exists($log, ServerLogs::TYPES) ? $log : 'provisioning';

        return response()->json(['overview' => $overview->handle($project, $user), ...$page->handle($project, $server, $user, $logType)]);
    }
}
