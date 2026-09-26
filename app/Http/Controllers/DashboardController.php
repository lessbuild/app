<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Queries\AccountProjectsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** The account home: its projects. */
final class DashboardController
{
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
