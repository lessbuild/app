<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RefreshServerCosts;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RefreshServerCostsController
{
    /**
     * Look up current prices for the account's servers.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  RefreshServerCosts  $refresh
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, RefreshServerCosts $refresh): JsonResponse
    {
        $priced = $refresh->handle($user, $project->account);

        return response()->json(['redirect' => route('infrastructure.costs', $project, false), 'message' => trans_choice('Prices checked for :count server.|Prices checked for :count servers.', $priced)]);
    }
}
