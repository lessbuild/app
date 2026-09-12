<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\RecipePublication;

class UpdateRecipeAction
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly RecipePublication $publication,
    ) {}

    /**
     * Update recipe content/publication state and record the resulting lifecycle activity.
     *
     * @param  User  $actor  Account performing the update, which owns the activity identity.
     * @param  Recipe  $recipe  Workspace recipe being updated.
     * @param  array<string, mixed>  $attributes  Validated recipe content and publication settings.
     * @return Recipe The updated recipe.
     */
    public function handle(User $actor, Recipe $recipe, array $attributes): Recipe
    {
        $wasPublished = $recipe->is_published;
        $recipe->update($this->publication->attributes($attributes, $recipe));
        $message = match (true) {
            ! $wasPublished && $recipe->is_published => "Recipe \"{$recipe->name}\" was published.",
            $wasPublished && ! $recipe->is_published => "Recipe \"{$recipe->name}\" was unpublished.",
            default => "Recipe \"{$recipe->name}\" was updated.",
        };
        $this->activity->record($recipe, $actor->id, 'recipe', $message);

        return $recipe;
    }
}
