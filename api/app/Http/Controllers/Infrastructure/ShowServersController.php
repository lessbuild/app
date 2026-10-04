<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Data\Infrastructure\ServerSummary;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowServersController
{
    /**
     * List the account's servers (every project can deploy to them), with how many the plan allows.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ServersQuery  $servers
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ServersQuery $servers, Entitlements $entitlements): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'accountName' => $project->account->name,
            'servers' => $servers->handle($project->account_id)->map(fn (Server $server): ServerSummary => ServerSummary::from($server))->values(),
            'limit' => $entitlements->for($project->account)->limit('infrastructure.servers.max'),
            'types' => ServerSummary::types(),
            'canManage' => $user->can('create', [Server::class, $project]),
        ]);
    }
}
