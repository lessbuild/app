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

final class ShowRecipeController
{
    /**
     * Show a recipe: its script and edit form, its gallery status (or the original's newer revision, for a copy), and
     * its revisions.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  Recipe  $recipe
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, Recipe $recipe): View
    {
        return view('recipes.show', [
            'account' => $account,
            'recipe' => $recipe->load(['source', 'creator', 'revisions' => fn ($query) => $query->with('user')->limit(Recipe::KEEP_REVISIONS)]),
            'categories' => RecipeCategory::cases(),
            'canUpdate' => $user->can('update', $recipe),
            'canPublish' => $user->can('publish', $recipe),
            'openReports' => $recipe->reports()->where('status', 'open')->count(),
        ]);
    }
}
