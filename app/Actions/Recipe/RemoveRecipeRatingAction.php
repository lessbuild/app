<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RemoveRecipeRatingAction
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Remove only the actor's rating for a recipe and record the removal.
     *
     * @throws ModelNotFoundException When the actor has no rating for the recipe.
     */
    public function handle(Recipe $recipe, User $rater): void
    {
        $rater->recipeRatings()
            ->where('recipe_id', $recipe->id)
            ->firstOrFail()
            ->delete();
        $this->activity->record(
            $recipe,
            $rater->id,
            'recipe',
            "Gallery recipe \"{$recipe->name}\" rating was removed.",
        );
    }
}
