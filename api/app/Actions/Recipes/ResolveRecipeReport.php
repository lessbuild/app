<?php

declare(strict_types=1);

namespace App\Actions\Recipes;

use App\Models\RecipeReport;
use App\Models\User;
use App\Notifications\RecipeReportResolved;
use Illuminate\Support\Facades\Gate;

final class ResolveRecipeReport
{
    /**
     * Resolve a report on one of the account's published recipes with an optional note for the reporter (who's told),
     * or reopen it.
     *
     * @param  User  $actor
     * @param  RecipeReport  $report
     * @param  bool  $resolved
     * @param  string|null  $note
     * @return void
     */
    public function handle(User $actor, RecipeReport $report, bool $resolved, ?string $note = null): void
    {
        Gate::forUser($actor)->authorize('resolve', $report);
        $report->forceFill($resolved
            ? ['status' => 'resolved', 'resolution_note' => $note, 'resolved_by' => $actor->id, 'resolved_at' => now()]
            : ['status' => 'open', 'resolved_by' => null, 'resolved_at' => null])->save();
        if ($resolved) {
            $report->user->notify(new RecipeReportResolved($report));
        }
    }
}
