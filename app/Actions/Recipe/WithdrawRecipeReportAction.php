<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\RecipeReportLocks;
use App\Services\RecipeReportNotifier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class WithdrawRecipeReportAction
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly RecipeReportLocks $locks,
        private readonly RecipeReportNotifier $notifications,
    ) {}

    /**
     * Withdraw one reporter-owned report under recipe/report locks and remove its linked notifications atomically.
     *
     * @param  Recipe  $recipe  The recipe whose report is being withdrawn.
     * @param  User  $reporter  The authenticated report owner.
     *
     * @throws ModelNotFoundException If the reporter has no report for the recipe.
     */
    public function handle(Recipe $recipe, User $reporter): void
    {
        DB::transaction(function () use ($recipe, $reporter): void {
            $lockedRecipe = $this->locks->recipe($recipe->id);
            $lockedReport = $reporter->recipeReports()
                ->where('recipe_id', $lockedRecipe->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->notifications->forget($lockedRecipe, $lockedReport);
            $lockedReport->delete();
            $this->activity->record(
                $lockedRecipe,
                $reporter->id,
                'recipe',
                "Gallery recipe \"{$lockedRecipe->name}\" report was withdrawn.",
            );
        });
    }
}
