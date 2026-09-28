<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\InstallRecipe;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class InstallRecipeController
{
    /**
     * Install a gallery recipe into the account (or find the copy it has) and open the copy.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $recipe
     * @param  InstallRecipe  $install
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $recipe, InstallRecipe $install): RedirectResponse
    {
        $copy = $install->handle($user, $account, Recipe::query()->published()->findOrFail(ctype_digit($recipe) ? (int) $recipe : 0));

        return to_route('account.recipes.show', $copy->id)->with('status', __('Installed. Choose it when you create a server.'));
    }
}
