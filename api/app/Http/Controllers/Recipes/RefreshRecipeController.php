<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\RefreshRecipeFromGallery;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RefreshRecipeController
{
    /**
     * Update an installed copy to its gallery original's latest revision and return to it.
     *
     * @param  User  $user
     * @param  Recipe  $recipe
     * @param  RefreshRecipeFromGallery  $refresh
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Recipe $recipe, RefreshRecipeFromGallery $refresh): RedirectResponse
    {
        $refresh->handle($user, $recipe);

        return to_route('account.recipes.show', $recipe->id)->with('status', __('Updated from the gallery. Your earlier version is in the history.'));
    }
}
