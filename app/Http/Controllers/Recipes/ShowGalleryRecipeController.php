<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Enums\RecipeReportReason;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Recipe;
use App\Models\User;
use App\Queries\Recipes\RecipeGalleryQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowGalleryRecipeController
{
    /**
     * Show a published recipe: its script to review before installing, its rating, and the viewer's install,
     * favourite, rating and report.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $recipe
     * @param  RecipeGalleryQuery  $gallery
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $recipe, RecipeGalleryQuery $gallery): View
    {
        $data = $gallery->recipe($user, $recipe);

        return view('recipes.gallery-recipe', [
            'account' => $account, ...$data, 'reasons' => RecipeReportReason::cases(),
            'canInstall' => $user->can('create', [Recipe::class, $account]), 'canRate' => $user->can('rate', $data['recipe']), 'canReport' => $user->can('report', $data['recipe']),
        ]);
    }
}
