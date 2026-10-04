<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\RecipeReport;
use App\Models\User;
use App\Queries\Recipes\RecipeReportsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowMyRecipeReportsController
{
    /**
     * List the gallery recipes the person reported, and what their publishers said.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  RecipeReportsQuery  $reports
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, RecipeReportsQuery $reports): JsonResponse
    {
        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'reports' => $reports->forReporter($user)->map(fn (RecipeReport $report): array => [
                'id' => $report->id,
                'recipeId' => $report->recipe->id,
                'recipe' => $report->recipe->name,
                'published' => (bool) $report->recipe->is_published,
                'reason' => $report->reason->label(),
                'status' => $report->status,
                'note' => $report->resolution_note,
            ])->values(),
            'canSeeReports' => $user->can('update', $account),
        ]);
    }
}
