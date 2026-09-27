<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SetServerMonthlyCost;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateServerMonthlyCostController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, SetServerMonthlyCost $set): RedirectResponse
    {
        $request->validate(['monthly_cost' => ['nullable', 'numeric', 'between:0,9999999'], 'monthly_cost_currency' => ['required', 'in:USD,EUR']]);
        $set->handle($user, $server, $request->filled('monthly_cost') ? $request->float('monthly_cost') : null, $request->string('monthly_cost_currency')->toString());

        return to_route('infrastructure.costs', $project)->with('status', __('Cost saved for :server.', ['server' => $server->label()]));
    }
}
