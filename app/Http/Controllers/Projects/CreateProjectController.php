<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class CreateProjectController
{
    /**
     * The new project form.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user): View
    {

        return view('projects.create', ['account' => $account]);
    }
}
