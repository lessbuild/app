<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\DeleteRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteRecipeController
{
    /**
     * Delete a recipe and return to the list.
     *
     * @param  User  $user
     * @param  Recipe  $recipe
     * @param  DeleteRecipe  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Recipe $recipe, DeleteRecipe $delete): JsonResponse
    {
        $delete->handle($user, $recipe);

        return response()->json(['redirect' => route('account.recipes', [], false), 'message' => __('Recipe deleted. Servers keep the copy they ran.')]);
    }
}
