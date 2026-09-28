<?php

declare(strict_types=1);

namespace App\Actions\Recipes;

use App\Models\Recipe;
use App\Models\RecipeFavorite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class FavoriteRecipe
{
    /**
     * Add a gallery recipe to the person's favourites, or take it out.
     *
     * @param  User  $actor
     * @param  Recipe  $recipe
     * @param  bool  $favorite
     * @return void
     */
    public function handle(User $actor, Recipe $recipe, bool $favorite): void
    {
        Gate::forUser($actor)->authorize('viewPublished', $recipe);
        $existing = RecipeFavorite::query()->where('recipe_id', $recipe->id)->where('user_id', $actor->id)->first();
        if (! $favorite) {
            $existing?->delete();

            return;
        }
        if ($existing === null) {
            $record = new RecipeFavorite;
            $record->forceFill(['recipe_id' => $recipe->id, 'user_id' => $actor->id])->save();
        }
    }
}
