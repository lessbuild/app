<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use App\Models\RecipeReport;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\RecipeReportNotifier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UpdateRecipeReportResolutionNoteAction
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly RecipeReportNotifier $notifications,
    ) {}

    /**
     * Update a resolved contributor-owned report note under recipe/report locks and notify the reporter when changed.
     *
     * @param  Recipe  $recipe  The recipe whose contributor is editing the note.
     * @param  RecipeReport  $report  The report whose note is being changed.
     * @param  User  $contributor  The authenticated recipe contributor.
     * @param  string|null  $resolutionNote  Validated and normalized replacement note.
     * @return bool Whether the encrypted note changed.
     *
     * @throws ConflictHttpException If the report is not currently resolved.
     * @throws ModelNotFoundException If the report is not owned by the contributor or no longer matches the recipe.
     */
    public function handle(Recipe $recipe, RecipeReport $report, User $contributor, ?string $resolutionNote): bool
    {
        return DB::transaction(function () use ($contributor, $recipe, $report, $resolutionNote): bool {
            $lockedRecipe = $this->lockedRecipe($recipe->id);
            $lockedReport = RecipeReport::query()
                ->whereKey($report->id)
                ->where('recipe_id', $lockedRecipe->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $lockedRecipe->user_id !== (int) $contributor->id) {
                throw (new ModelNotFoundException)->setModel(RecipeReport::class, [$lockedReport->id]);
            }
            if ($lockedReport->resolved_at === null) {
                throw new ConflictHttpException;
            }
            if ($lockedReport->resolution_note === $resolutionNote) {
                return false;
            }

            $lockedReport->update(['resolution_note' => $resolutionNote]);
            $this->notifications->resolved([$lockedReport->id]);
            $this->activity->record(
                $lockedRecipe,
                $contributor->id,
                'recipe',
                "A community report resolution note for gallery recipe \"{$lockedRecipe->name}\" was updated.",
            );

            return true;
        });
    }

    /**
     * Take the same row lock used by the controller workflow.
     */
    private function lockedRecipe(int $recipeId): Recipe
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Recipe::query()->whereKey($recipeId)->update(['id' => DB::raw('id')]);
        }

        return Recipe::query()->whereKey($recipeId)->lockForUpdate()->firstOrFail();
    }
}
