<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;

/** Keeps each recipe's saved versions, pruned to the newest KEEP_REVISIONS. */
final class RecipeRevisions
{
    /**
     * Record the recipe as it is now, saying who saved it and why, and drop revisions past the limit.
     *
     * @param  Recipe  $recipe
     * @param  User|null  $user
     * @param  string  $change  created, edited, installed, refreshed or duplicated
     * @return RecipeRevision
     */
    public function record(Recipe $recipe, ?User $user, string $change): RecipeRevision
    {
        $revision = new RecipeRevision;
        $revision->forceFill([
            'recipe_id' => $recipe->id, 'user_id' => $user?->id, 'change' => $change,
            'name' => $recipe->name, 'description' => $recipe->description, 'script' => $recipe->script,
        ])->save();
        RecipeRevision::query()->where('recipe_id', $recipe->id)
            ->whereKeyNot(RecipeRevision::query()->where('recipe_id', $recipe->id)->latest('id')->limit(Recipe::KEEP_REVISIONS)->pluck('id'))->delete();

        return $revision;
    }
}
