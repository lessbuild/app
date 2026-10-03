<?php

declare(strict_types=1);

namespace App\Actions\Recipes;

use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RateRecipe
{
    /**
     * Rate a gallery recipe from 1 to 5, replacing the person's earlier rating, or withdraw it (null).
     *
     * @param  User  $actor
     * @param  Recipe  $recipe
     * @param  int|null  $rating
     * @return void
     */
    public function handle(User $actor, Recipe $recipe, ?int $rating): void
    {
        Gate::forUser($actor)->authorize('rate', $recipe);
        $existing = RecipeRating::query()->where('recipe_id', $recipe->id)->where('user_id', $actor->id)->first();
        if ($rating === null) {
            $existing?->delete();

            return;
        }
        $record = $existing ?? new RecipeRating;
        $record->forceFill(['recipe_id' => $recipe->id, 'user_id' => $actor->id, 'rating' => max(1, min(5, $rating))])->save();
    }
}
