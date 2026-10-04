<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Data\Recipes\RecipeChoices;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowRecipesController
{
    /**
     * List the account's recipes: scripts that run on new servers, its own or installed from the gallery.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user): JsonResponse
    {
        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'recipes' => Recipe::query()->where('account_id', $account->id)->with('source')->orderBy('name')->get()->map(fn (Recipe $recipe): array => [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'category' => $recipe->category->label(),
                'description' => $recipe->description,
                'published' => (bool) $recipe->is_published,
                'installs' => (int) $recipe->install_count,
                'fromGallery' => $recipe->source_recipe_id !== null,
                'updateAvailable' => $recipe->hasGalleryUpdate(),
            ])->values(),
            'categories' => RecipeChoices::categories(),
            'canCreate' => $user->can('create', [Recipe::class, $account]),
            'canSeeReports' => $user->can('update', $account),
        ]);
    }
}
