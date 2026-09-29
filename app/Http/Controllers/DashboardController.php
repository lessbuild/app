<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Queries\Dashboard\AccountActivityQuery;
use App\Queries\Projects\AccountProjectsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** The account home: its projects and what the team has been doing. */
final class DashboardController
{
    /**
     * Show the dashboard: the current account's projects, whether the person may create one, and the team's recent
     * activity, filtered to one kind with `?activity=`.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  AccountProjectsQuery  $projects
     * @param  AccountActivityQuery  $activity
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, AccountProjectsQuery $projects, AccountActivityQuery $activity): View
    {
        $account = $user->currentAccount;
        $kind = $request->query('activity');
        $kind = is_string($kind) && isset(AccountActivityQuery::KINDS[$kind]) ? $kind : null;

        return view('dashboard', [
            'account' => $account,
            'projects' => $account !== null ? $projects->handle($account) : [],
            'activity' => $account !== null ? $activity->handle($account, $kind) : [],
            'activityKind' => $kind,
            'canCreate' => $account !== null && $user->can('create', [Project::class, $account]),
        ]);
    }
}
