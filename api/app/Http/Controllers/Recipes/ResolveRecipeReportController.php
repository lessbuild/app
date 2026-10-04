<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Actions\Recipes\ResolveRecipeReport;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\RecipeReport;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResolveRecipeReportController
{
    /**
     * Resolve a report on one of the account's recipes with a note, or reopen it, and return to the reports.
     *
     * @param  Request  $request
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $report
     * @param  ResolveRecipeReport  $resolve
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentAccount] Account $account, #[CurrentUser] User $user, string $report, ResolveRecipeReport $resolve): JsonResponse
    {
        $request->validate(['resolved' => ['required', 'boolean'], 'resolution_note' => ['nullable', 'string', 'max:2000']]);
        $record = RecipeReport::query()->whereHas('recipe', fn ($query) => $query->where('account_id', $account->id))->findOrFail(ctype_digit($report) ? (int) $report : 0);
        $note = trim($request->string('resolution_note')->toString());
        $resolve->handle($user, $record, $request->boolean('resolved'), $note === '' ? null : $note);

        return response()->json(['redirect' => route('account.recipes.reports', [], false), 'message' => $request->boolean('resolved') ? __('Report resolved. The reporter has been told.') : __('Report reopened.')]);
    }
}
