<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Queries\Infrastructure\InfrastructureCostsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowCostsController
{
    /**
     * The costs page, with the budget for people allowed to set it.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  InfrastructureCostsQuery  $costs
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, InfrastructureCostsQuery $costs): View
    {
        return view('infrastructure.costs', [
            'overview' => $overview->handle($project, $user),
            'report' => $costs->handle($project->account_id),
            'budget' => $project->account->monthly_infrastructure_budget,
            'canManage' => $user->can('create', [Server::class, $project]),
            'canBudget' => $user->can('manageCosts', [Server::class, $project]),
        ]);
    }
}
