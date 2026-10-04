<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\SaveRecipe;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Recipes\RecipeRequest;
use App\Models\Account;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateRecipeController
{
    /**
     * Save changes to a recipe and return to it.
     *
     * @param  RecipeRequest  $request
     * @param  Account  $account
     * @param  User  $user
     * @param  Recipe  $recipe
     * @param  SaveRecipe  $save
     * @return JsonResponse
     */
    public function __invoke(RecipeRequest $request, #[CurrentAccount] Account $account, #[CurrentUser] User $user, Recipe $recipe, SaveRecipe $save): JsonResponse
    {
        $save->handle($user, $account, $recipe, $request->recipe());

        return response()->json(['redirect' => route('account.recipes.show', $recipe->id, false), 'message' => $recipe->is_published ? __('Recipe saved. Installed copies can refresh to this version.') : __('Recipe saved.')]);
    }
}
