<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SetInfrastructureBudget;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateInfrastructureBudgetController
{
    /**
     * Set or clears the account's monthly infrastructure budget.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SetInfrastructureBudget  $set
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, SetInfrastructureBudget $set): JsonResponse
    {
        $request->validate(['monthly_infrastructure_budget' => ['nullable', 'numeric', 'between:0,99999999']]);
        $set->handle($user, $project->account, $request->filled('monthly_infrastructure_budget') ? $request->float('monthly_infrastructure_budget') : null);

        return response()->json(['redirect' => route('infrastructure.costs', $project, false), 'message' => __('Budget saved.')]);
    }
}
