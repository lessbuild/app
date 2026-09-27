<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class CreateProjectController
{
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user): View
    {
        Gate::authorize('create', [Project::class, $account]);

        return view('projects.create', ['account' => $account]);
    }
}
