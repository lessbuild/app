<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use App\Services\ActivityRecorder;
use App\Services\RecipeReportNotifier;
use Illuminate\Support\Facades\DB;

class DeleteRecipeAction
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly RecipeReportNotifier $notifications,
    ) {}

    /**
     * Delete a recipe under its existing lock, removing linked report notifications atomically.
     *
     * @param  Recipe  $recipe  Workspace recipe being deleted.
     */
    public function handle(Recipe $recipe): void
    {
        DB::transaction(function () use ($recipe): void {
            $lockedRecipe = $this->lockedRecipe($recipe->id);
            $this->notifications->forgetRecipe($lockedRecipe);
            $lockedRecipe->delete();
            $this->activity->record(
                $lockedRecipe,
                $lockedRecipe->user_id,
                'recipe',
                "Recipe \"{$lockedRecipe->name}\" was deleted.",
            );
        });
    }

    /**
     * Load a recipe under a transaction lock, reserving the SQLite writer when required by the test/runtime driver.
     */
    private function lockedRecipe(int $recipeId): Recipe
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Recipe::query()->whereKey($recipeId)->update(['id' => DB::raw('id')]);
        }

        return Recipe::query()->whereKey($recipeId)->lockForUpdate()->firstOrFail();
    }
}
