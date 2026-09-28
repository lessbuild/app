<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\FavoriteRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class FavoriteRecipeController
{
    /**
     * Favourite a gallery recipe (PUT) or unfavourite it (DELETE), and go back.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $recipe
     * @param  FavoriteRecipe  $favorite
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $recipe, FavoriteRecipe $favorite): RedirectResponse
    {
        $favorite->handle($user, Recipe::query()->published()->findOrFail(ctype_digit($recipe) ? (int) $recipe : 0), $request->isMethod('PUT'));

        return back();
    }
}
