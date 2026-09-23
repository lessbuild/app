<?php

namespace App\Modules\Deployer\Actions\Recipe;

use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\RecipeReport;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ActivityRecorder;
use App\Modules\Deployer\Services\RecipeReportLocks;
use App\Modules\Deployer\Services\RecipeReportNotifier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ResolveRecipeReportAction
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly RecipeReportLocks $locks,
        private readonly RecipeReportNotifier $notifications,
    ) {}

    /**
     * Resolve one contributor-owned report under recipe/report locks and preserve its idempotent notification behavior.
     *
     * @param  Recipe  $recipe  The recipe whose contributor is reviewing the report.
     * @param  RecipeReport  $report  The report to resolve.
     * @param  User  $contributor  The authenticated recipe contributor.
     * @param  string|null  $resolutionNote  Validated and normalized note text.
     *
     * @throws ModelNotFoundException If the report is not owned by the contributor or no longer matches the recipe.
     */
    public function handle(Recipe $recipe, RecipeReport $report, User $contributor, ?string $resolutionNote): void
    {
        DB::transaction(function () use ($contributor, $recipe, $report, $resolutionNote): void {
            $lockedRecipe = $this->locks->recipe($recipe->id);
            $lockedReport = RecipeReport::query()
                ->whereKey($report->id)
                ->where('recipe_id', $lockedRecipe->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $lockedRecipe->user_id !== (int) $contributor->id) {
                throw (new ModelNotFoundException)->setModel(RecipeReport::class, [$lockedReport->id]);
            }

            $wasResolved = $lockedReport->resolved_at === null;

            if ($wasResolved) {
                $lockedReport->update([
                    'resolved_at' => now(),
                    'resolution_note' => $resolutionNote,
                ]);
            }
            $this->notifications->resolve($contributor, [$lockedReport->id]);
            if ($wasResolved) {
                $this->notifications->resolved([$lockedReport->id]);
                $this->activity->record(
                    $lockedRecipe,
                    $contributor->id,
                    'recipe',
                    "A community report for gallery recipe \"{$lockedRecipe->name}\" was resolved.",
                );
            }
        });
    }
}
