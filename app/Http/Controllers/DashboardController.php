<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\AccountProjectsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** The account home: its projects. */
final class DashboardController
{
    /**
     * The dashboard: the current account's projects, and whether the person may create one.
     */
    public function __invoke(#[CurrentUser] User $user, AccountProjectsQuery $projects): View
    {
        $account = $user->currentAccount;

        return view('dashboard', [
            'account' => $account,
            'projects' => $account !== null ? $projects->handle($account) : [],
            'canCreate' => $account !== null && $user->can('create', [Project::class, $account]),
        ]);
    }
}
