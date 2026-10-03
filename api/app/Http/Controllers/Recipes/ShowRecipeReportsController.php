<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Queries\Recipes\RecipeReportsQuery;
use Illuminate\Contracts\View\View;

final class ShowRecipeReportsController
{
    /**
     * Show the reports on the account's gallery recipes, open ones first.
     *
     * @param  Account  $account
     * @param  RecipeReportsQuery  $reports
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, RecipeReportsQuery $reports): View
    {
        return view('recipes.reports', ['account' => $account, 'reports' => $reports->forPublisher($account->id)]);
    }
}
