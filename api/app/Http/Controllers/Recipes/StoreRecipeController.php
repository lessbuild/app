<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\SaveRecipe;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Recipes\RecipeRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreRecipeController
{
    /**
     * Create a recipe in the account and open it.
     *
     * @param  RecipeRequest  $request
     * @param  Account  $account
     * @param  User  $user
     * @param  SaveRecipe  $save
     * @return JsonResponse
     */
    public function __invoke(RecipeRequest $request, #[CurrentAccount] Account $account, #[CurrentUser] User $user, SaveRecipe $save): JsonResponse
    {
        $recipe = $save->handle($user, $account, null, $request->recipe());

        return response()->json(['redirect' => route('account.recipes.show', $recipe->id, false), 'message' => __('Recipe saved. Choose it when you create a server.')]);
    }
}
