<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SetInfrastructureBudget;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateInfrastructureBudgetController
{
    /**
     * Sets or clears the account's monthly infrastructure budget.
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, SetInfrastructureBudget $set): RedirectResponse
    {
        $request->validate(['monthly_infrastructure_budget' => ['nullable', 'numeric', 'between:0,99999999']]);
        $set->handle($user, $project->account, $request->filled('monthly_infrastructure_budget') ? $request->float('monthly_infrastructure_budget') : null);

        return to_route('infrastructure.costs', $project)->with('status', __('Budget saved.'));
    }
}
