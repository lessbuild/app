<?php

declare(strict_types=1);

namespace App\Http\Controllers\Services;

use App\Models\User;
use App\Platform\ServiceRegistry;
use App\Queries\Projects\ServiceProjectsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/** A service across the account: which projects use it. Each service adds its own account-wide views in Phase 4. */
final class ShowServiceController
{
    public function __invoke(#[CurrentUser] User $user, string $service, ServiceRegistry $services, ServiceProjectsQuery $query): View
    {
        $definition = $services->find($service) ?? abort(404);
        $account = $user->currentAccount ?? abort(404);
        Gate::authorize('useService', [$account, $service]);

        return view('services.show', [
            'account' => $account,
            'service' => $definition,
            'projects' => $query->handle($account, $service, $user),
        ]);
    }
}
