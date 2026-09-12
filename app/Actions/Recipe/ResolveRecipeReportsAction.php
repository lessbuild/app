<?php

namespace App\Actions\Recipe;

use App\Models\RecipeReport;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\RecipeReportNotifier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ResolveRecipeReportsAction
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly RecipeReportNotifier $notifications,
    ) {}

    /**
     * Resolve a sorted, contributor-owned report selection atomically and record one audit event per recipe.
     *
     * @param  User  $contributor  The authenticated recipe contributor.
     * @param  list<int>  $reportIds  Validated, distinct report IDs in deterministic order.
     * @return int The number of reports that changed from open to resolved.
     *
     * @throws ModelNotFoundException If any selected report is missing or outside the contributor's recipes.
     */
    public function handle(User $contributor, array $reportIds): int
    {
        return DB::transaction(function () use ($contributor, $reportIds): int {
            $reports = RecipeReport::query()
                ->whereIn('id', $reportIds)
                ->whereHas('recipe', fn ($query) => $query->where('user_id', $contributor->id))
                ->select(['id', 'recipe_id', 'resolved_at'])
                ->with('recipe:id,user_id,name')
                ->lockForUpdate()
                ->get();

            if ($reports->count() !== count($reportIds)) {
                throw (new ModelNotFoundException)->setModel(RecipeReport::class, $reportIds);
            }

            $unresolved = $reports->whereNull('resolved_at');
            if ($unresolved->isEmpty()) {
                $this->notifications->resolve($contributor, $reportIds);

                return 0;
            }

            RecipeReport::query()
                ->whereKey($unresolved->modelKeys())
                ->update([
                    'resolved_at' => now(),
                    'resolution_note' => null,
                    'updated_at' => now(),
                ]);

            $this->notifications->resolve($contributor, $reportIds);
            $this->notifications->resolved($unresolved->modelKeys());

            $unresolved->groupBy('recipe_id')->each(function ($reports) use ($contributor): void {
                $recipe = $reports->first()->recipe;
                $this->activity->record(
                    $recipe,
                    $contributor->id,
                    'recipe',
                    trans_choice(
                        ':count community report for gallery recipe ":recipe" was resolved.|:count community reports for gallery recipe ":recipe" were resolved.',
                        $reports->count(),
                        ['count' => $reports->count(), 'recipe' => $recipe->name],
                    ),
                );
            });

            return $unresolved->count();
        });
    }
}
