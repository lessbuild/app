<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Data\Recipes\RecipeChoices;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowRecipeController
{
    /**
     * Show one of the account's recipes: its script, its place in the gallery, any newer gallery version, and its
     * recent revisions.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  Recipe  $recipe
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, Recipe $recipe): JsonResponse
    {
        $recipe->load(['source', 'creator', 'revisions' => fn ($query) => $query->with('user')->limit(Recipe::KEEP_REVISIONS)]);

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'recipe' => [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'category' => $recipe->category->value,
                'description' => $recipe->description,
                'script' => $recipe->script,
                'published' => (bool) $recipe->is_published,
                'installs' => (int) $recipe->install_count,
                'source' => $recipe->source === null ? null : ['id' => $recipe->source->id, 'name' => $recipe->source->name, 'script' => $recipe->source->script],
                'fromGallery' => $recipe->source_recipe_id !== null,
                'updateAvailable' => $recipe->hasGalleryUpdate(),
            ],
            'revisions' => $recipe->revisions->map(fn (RecipeRevision $revision): array => [
                'id' => $revision->id,
                'change' => $revision->change,
                'user' => $revision->user?->name,
                'name' => $revision->name,
                'description' => $revision->description,
                'script' => $revision->script,
                'createdAt' => $revision->created_at->toIso8601String(),
            ])->values(),
            'keepRevisions' => Recipe::KEEP_REVISIONS,
            'categories' => RecipeChoices::categories(),
            'openReports' => $recipe->reports()->where('status', 'open')->count(),
            'canUpdate' => $user->can('update', $recipe),
            'canPublish' => $user->can('publish', $recipe),
            'canSeeReports' => $user->can('update', $account),
        ]);
    }
}
