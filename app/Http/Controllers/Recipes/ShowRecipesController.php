<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Enums\RecipeCategory;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowRecipesController
{
    /**
     * Show the account's recipes, marking installed copies whose gallery original has a newer revision, with the form
     * for a new one.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user): View
    {
        return view('recipes.index', [
            'account' => $account,
            'recipes' => Recipe::query()->where('account_id', $account->id)->with('source')->orderBy('name')->get(),
            'categories' => RecipeCategory::cases(),
            'canCreate' => $user->can('create', [Recipe::class, $account]),
        ]);
    }
}
