<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\DashboardForm;
use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\DashboardsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\TelemetrySummaryQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowDashboardsController
{
    /**
     * List the account's dashboards, with the plan's limit and the choices for adding one.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  DashboardsQuery  $dashboards
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, DashboardsQuery $dashboards, Entitlements $entitlements): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'accountName' => $project->account->name,
            'dashboards' => $dashboards->handle($project->account_id)->map(fn (Dashboard $dashboard): array => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'widgets' => (int) ($dashboard->widgets_count ?? 0),
                'range' => __(TelemetrySummaryQuery::RANGES[$dashboard->range] ?? ''),
                'creator' => $dashboard->creator?->name,
            ])->values(),
            'limit' => $entitlements->for($project->account)->limit('monitoring.dashboards.max'),
            'form' => DashboardForm::for(null),
            'canManage' => $user->can('create', [Dashboard::class, $project]),
        ]);
    }
}
