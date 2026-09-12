<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\RecipeReportLocks;
use App\Services\RecipeReportNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class SubmitRecipeReportAction
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly RecipeReportLocks $locks,
        private readonly RecipeReportNotifier $notifications,
    ) {}

    /**
     * Create or update one reporter-owned gallery report under the existing recipe lock and notify its contributor.
     *
     * @param  Recipe  $recipe  The published recipe being reported.
     * @param  User  $reporter  The authenticated user submitting the report.
     * @param  array{reason: string, details: ?string}  $data  Validated and normalized report input.
     *
     * @throws AuthorizationException If the reporter owns the recipe.
     * @throws ModelNotFoundException If the recipe is no longer published or cannot be found.
     */
    public function handle(Recipe $recipe, User $reporter, array $data): void
    {
        DB::transaction(function () use ($data, $recipe, $reporter): void {
            $lockedRecipe = $this->locks->recipe($recipe->id);
            if (! $lockedRecipe->is_published || $lockedRecipe->published_at === null) {
                throw (new ModelNotFoundException)->setModel(Recipe::class, [$lockedRecipe->id]);
            }
            if ((int) $lockedRecipe->user_id === (int) $reporter->id) {
                throw new AuthorizationException;
            }

            $report = $reporter->recipeReports()
                ->where('recipe_id', $lockedRecipe->id)
                ->lockForUpdate()
                ->first();
            if ($report === null) {
                $report = $reporter->recipeReports()->create([
                    'recipe_id' => $lockedRecipe->id,
                    'reason' => $data['reason'],
                    'details' => $data['details'],
                    'resolved_at' => null,
                    'resolution_note' => null,
                ]);
            } else {
                $report->fill([
                    'reason' => $data['reason'],
                    'details' => $data['details'],
                    'resolved_at' => null,
                    'resolution_note' => null,
                ])->save();
            }

            $this->notifications->open($lockedRecipe, $report);
            $this->activity->record(
                $lockedRecipe,
                $reporter->id,
                'recipe',
                $report->wasRecentlyCreated
                    ? "Gallery recipe \"{$lockedRecipe->name}\" was reported as {$report->reason}."
                    : "Gallery recipe \"{$lockedRecipe->name}\" report was updated to {$report->reason}.",
            );
        });
    }
}
