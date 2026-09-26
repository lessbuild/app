<?php

namespace App\Modules\Deployer\Actions\Recipe;

use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\RecipeRating;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ActivityRecorder;

class SaveRecipeRatingAction
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Create or update the actor's rating for a published recipe and record the resulting activity.
     *
     * @param  Recipe  $recipe  Published recipe whose rating is being saved.
     * @param  User  $rater  Actor whose existing rating is replaced when present.
     * @param  int  $rating  Validated rating from one through five.
     * @return RecipeRating The persisted actor-owned rating.
     */
    public function handle(Recipe $recipe, User $rater, int $rating): RecipeRating
    {
        $saved = $rater->recipeRatings()->updateOrCreate(
            ['recipe_id' => $recipe->id],
            ['rating' => $rating],
        );
        $this->activity->record(
            $recipe,
            $rater->id,
            'recipe',
            $saved->wasRecentlyCreated
                ? "Gallery recipe \"{$recipe->name}\" was rated {$saved->rating}/5."
                : "Gallery recipe \"{$recipe->name}\" rating was updated to {$saved->rating}/5.",
        );

        return $saved;
    }
}
