<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\RecipeReport;
use App\Queries\Recipes\RecipeReportsQuery;
use Illuminate\Http\JsonResponse;

final class ShowRecipeReportsController
{
    /**
     * List what people reported about the recipes this account published.
     *
     * @param  Account  $account
     * @param  RecipeReportsQuery  $reports
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, RecipeReportsQuery $reports): JsonResponse
    {
        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'reports' => $reports->forPublisher($account->id)->map(fn (RecipeReport $report): array => [
                'id' => $report->id,
                'recipe' => $report->recipe->name,
                'reason' => $report->reason->label(),
                'reporter' => $report->user->name,
                'details' => $report->details,
                'status' => $report->status,
                'resolver' => $report->resolver?->name,
                'note' => $report->resolution_note,
                'updatedAt' => $report->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
