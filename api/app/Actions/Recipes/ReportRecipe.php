<?php

declare(strict_types=1);

namespace App\Actions\Recipes;

use App\Enums\AccountPermission;
use App\Enums\RecipeReportReason;
use App\Models\Membership;
use App\Models\Recipe;
use App\Models\RecipeReport;
use App\Models\User;
use App\Notifications\RecipeReported;
use Illuminate\Support\Facades\Gate;

final class ReportRecipe
{
    /**
     * Report a gallery recipe, or change the person's report (which opens it again), and tell the publisher's owners
     * and admins. Passing no reason withdraws the report.
     *
     * @param  User  $actor
     * @param  Recipe  $recipe
     * @param  RecipeReportReason|null  $reason
     * @param  string|null  $details
     * @return RecipeReport|null
     */
    public function handle(User $actor, Recipe $recipe, ?RecipeReportReason $reason, ?string $details = null): ?RecipeReport
    {
        Gate::forUser($actor)->authorize('report', $recipe);
        $report = RecipeReport::query()->where('recipe_id', $recipe->id)->where('user_id', $actor->id)->first();
        if ($reason === null) {
            $report?->delete();

            return null;
        }
        $report ??= new RecipeReport;
        $report->forceFill([
            'recipe_id' => $recipe->id, 'user_id' => $actor->id, 'reason' => $reason, 'details' => $details,
            'status' => 'open', 'resolution_note' => null, 'resolved_by' => null, 'resolved_at' => null,
        ])->save();
        Membership::query()->where('account_id', $recipe->account_id)->with('user')->get()
            ->filter(fn (Membership $membership): bool => $membership->role->allows(AccountPermission::ManageSettings))
            ->each(fn (Membership $membership) => $membership->user->notify(new RecipeReported($report)));

        return $report;
    }
}
