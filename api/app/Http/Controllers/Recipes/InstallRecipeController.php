<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\InstallRecipe;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class InstallRecipeController
{
    /**
     * Install a gallery recipe into the account (or find the copy it has) and open the copy.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $recipe
     * @param  InstallRecipe  $install
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $recipe, InstallRecipe $install): JsonResponse
    {
        $copy = $install->handle($user, $account, Recipe::query()->published()->findOrFail(ctype_digit($recipe) ? (int) $recipe : 0));

        return response()->json(['redirect' => route('account.recipes.show', $copy->id, false), 'message' => __('Installed. Choose it when you create a server.')]);
    }
}
