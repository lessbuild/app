<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SetServerMonthlyCost;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateServerMonthlyCostController
{
    /**
     * Set a server's monthly cost by hand, for servers whose provider doesn't report one.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  SetServerMonthlyCost  $set
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, SetServerMonthlyCost $set): JsonResponse
    {
        $request->validate(['monthly_cost' => ['nullable', 'numeric', 'between:0,9999999'], 'monthly_cost_currency' => ['required', 'in:USD,EUR']]);
        $set->handle($user, $server, $request->filled('monthly_cost') ? $request->float('monthly_cost') : null, $request->string('monthly_cost_currency')->toString());

        return response()->json(['redirect' => route('infrastructure.costs', $project, false), 'message' => __('Cost saved for :server.', ['server' => $server->label()])]);
    }
}
