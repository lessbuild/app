<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\DeleteRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteRecipeController
{
    /**
     * Delete a recipe and return to the list.
     *
     * @param  User  $user
     * @param  Recipe  $recipe
     * @param  DeleteRecipe  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Recipe $recipe, DeleteRecipe $delete): RedirectResponse
    {
        $delete->handle($user, $recipe);

        return to_route('account.recipes')->with('status', __('Recipe deleted. Servers keep the copy they ran.'));
    }
}
