<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Queries\Infrastructure\InfrastructureCostsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Infrastructure\ServerRightsizing;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowCostsController
{
    /**
     * Show the costs page, with the budget for people allowed to set it.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  InfrastructureCostsQuery  $costs
     * @param  ServerRightsizing  $rightsizing
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, InfrastructureCostsQuery $costs, ServerRightsizing $rightsizing): View
    {
        return view('infrastructure.costs', [
            'overview' => $overview->handle($project, $user),
            'report' => $costs->handle($project->account_id),
            'rightsizing' => $rightsizing->suggestions(Server::query()->where('account_id', $project->account_id)->where('provisioning_status', Server::STATUS_ACTIVE)->with('provider')->get()),
            'budget' => $project->account->monthly_infrastructure_budget,
            'canManage' => $user->can('create', [Server::class, $project]),
            'canBudget' => $user->can('manageCosts', [Server::class, $project]),
        ]);
    }
}
