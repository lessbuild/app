<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Recipes\RecipeReportsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowMyRecipeReportsController
{
    /**
     * Show the reports the person filed and what the publishers said.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  RecipeReportsQuery  $reports
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, RecipeReportsQuery $reports): View
    {
        return view('recipes.my-reports', ['account' => $account, 'reports' => $reports->forReporter($user)]);
    }
}
