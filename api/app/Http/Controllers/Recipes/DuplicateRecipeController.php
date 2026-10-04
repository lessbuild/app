<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\DuplicateRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DuplicateRecipeController
{
    /**
     * Copy a recipe and open the copy.
     *
     * @param  User  $user
     * @param  Recipe  $recipe
     * @param  DuplicateRecipe  $duplicate
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Recipe $recipe, DuplicateRecipe $duplicate): JsonResponse
    {
        $copy = $duplicate->handle($user, $recipe);

        return response()->json(['redirect' => route('account.recipes.show', $copy->id, false), 'message' => __('Recipe copied. Rename it before you use it.')]);
    }
}
