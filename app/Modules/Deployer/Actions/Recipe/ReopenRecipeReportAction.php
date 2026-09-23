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

class ReopenRecipeReportAction
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly RecipeReportLocks $locks,
        private readonly RecipeReportNotifier $notifications,
    ) {}

    /**
     * Reopen one contributor-owned report under recipe/report locks and notify both report participants.
     *
     * @param  Recipe  $recipe  The recipe whose contributor is reviewing the report.
     * @param  RecipeReport  $report  The report to reopen.
     * @param  User  $contributor  The authenticated recipe contributor.
     *
     * @throws ModelNotFoundException If the report is not owned by the contributor or no longer matches the recipe.
     */
    public function handle(Recipe $recipe, RecipeReport $report, User $contributor): void
    {
        DB::connection('deployer')->transaction(function () use ($contributor, $recipe, $report): void {
            $lockedRecipe = $this->locks->recipe($recipe->id);
            $lockedReport = RecipeReport::query()
                ->whereKey($report->id)
                ->where('recipe_id', $lockedRecipe->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $lockedRecipe->user_id !== (int) $contributor->id) {
                throw (new ModelNotFoundException)->setModel(RecipeReport::class, [$lockedReport->id]);
            }
            if ($lockedReport->resolved_at === null) {
                return;
            }

            $lockedReport->update([
                'resolved_at' => null,
                'resolution_note' => null,
            ]);
            $this->notifications->open($lockedRecipe, $lockedReport);
            $this->notifications->reopened($lockedRecipe, $lockedReport);
            $this->activity->record(
                $lockedRecipe,
                $contributor->id,
                'recipe',
                "A community report for gallery recipe \"{$lockedRecipe->name}\" was reopened.",
            );
        });
    }
}
