<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Data\Infrastructure\ServerSummary;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use App\Services\Infrastructure\ServerProvisioningPlan;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowServersController
{
    /**
     * Return the account's servers with their latest CPU, memory and disk use, monthly cost, whether each was imported
     * and how far set-up has got, plus the plan's server limit and the server types for the import form.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ServersQuery  $servers
     * @param  Entitlements  $entitlements
     * @param  ServerProvisioningPlan  $plan
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ServersQuery $servers, Entitlements $entitlements, ServerProvisioningPlan $plan): JsonResponse
    {
        $list = $servers->handle($project->account_id);
        $metrics = ServerMetric::query()->whereIn('id', ServerMetric::query()->whereIn('server_id', $list->modelKeys())->where('recorded_at', '>=', now()->subHour())->selectRaw('MAX(id)')->groupBy('server_id'))
            ->get()->keyBy('server_id');

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'accountName' => $project->account->name,
            'servers' => $list->map(function (Server $server) use ($metrics, $plan): array {
                $metric = $metrics->get($server->id);

                return [
                    ...(array) ServerSummary::from($server),
                    'imported' => $server->provider_id === null,
                    'usage' => $metric instanceof ServerMetric ? ['cpu' => $metric->cpu_percent, 'memory' => $metric->memory_percent, 'disk' => $metric->disk_percent] : null,
                    'monthlyCost' => $server->monthly_cost,
                    'currency' => $server->monthly_cost_currency,
                    'stage' => $server->setup_stage,
                    'finalStage' => $plan->finalStage($server),
                ];
            })->values(),
            'limit' => $entitlements->for($project->account)->limit('infrastructure.servers.max'),
            'types' => ServerSummary::types(),
            'canManage' => $user->can('create', [Server::class, $project]),
        ]);
    }
}
