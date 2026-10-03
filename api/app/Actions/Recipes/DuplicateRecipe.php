<?php

declare(strict_types=1);

namespace App\Actions\Recipes;

use App\Models\Recipe;
use App\Models\User;
use App\Services\Deploy\RecipeRevisions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class DuplicateRecipe
{
    /**
     * Create a new DuplicateRecipe instance.
     *
     * Copies recipes within an account.
     *
     * @param  RecipeRevisions  $revisions  Starts the copy's history.
     */
    public function __construct(private readonly RecipeRevisions $revisions) {}

    /**
     * Copy a recipe in its account as "Copy of …", unpublished and unlinked from any gallery source.
     *
     * @param  User  $actor
     * @param  Recipe  $recipe
     * @return Recipe
     */
    public function handle(User $actor, Recipe $recipe): Recipe
    {
        Gate::forUser($actor)->authorize('update', $recipe);

        return DB::transaction(function () use ($actor, $recipe): Recipe {
            $copy = new Recipe;
            $copy->forceFill([
                'account_id' => $recipe->account_id, 'created_by' => $actor->id, 'name' => Str::limit(__('Copy of :name', ['name' => $recipe->name]), 120, ''),
                'description' => $recipe->description, 'category' => $recipe->category, 'script' => $recipe->script,
            ])->save();
            $this->revisions->record($copy, $actor, 'duplicated');

            return $copy;
        });
    }
}
