<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\RecipePublication;

class CreateRecipeAction
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly RecipePublication $publication,
    ) {}

    /**
     * Create a workspace recipe with publication metadata and record its creation activity.
     *
     * @param  User  $owner  Actor and workspace owner for the new recipe.
     * @param  array<string, mixed>  $attributes  Validated recipe content and publication settings.
     * @return Recipe The created recipe.
     */
    public function handle(User $owner, array $attributes): Recipe
    {
        $recipe = $owner->workspaceRecipes()->create($this->publication->attributes($attributes));
        $this->activity->record(
            $recipe,
            $owner->id,
            'recipe',
            $recipe->is_published
                ? "Recipe \"{$recipe->name}\" was created and published."
                : "Recipe \"{$recipe->name}\" was created.",
        );

        return $recipe;
    }
}
