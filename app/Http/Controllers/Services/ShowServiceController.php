<?php

declare(strict_types=1);

namespace App\Http\Controllers\Services;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use App\Platform\ServiceRegistry;
use App\Queries\Projects\ServiceProjectsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** A service across the account: which projects use it. Each service adds its own account-wide views in Phase 4. */
final class ShowServiceController
{
    /**
     * A service's account-wide page: which projects use it.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $service
     * @param  ServiceRegistry  $services
     * @param  ServiceProjectsQuery  $query
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $service, ServiceRegistry $services, ServiceProjectsQuery $query): View
    {
        $definition = $services->find($service) ?? abort(404);

        return view('services.show', [
            'account' => $account,
            'service' => $definition,
            'projects' => $query->handle($account, $service, $user),
        ]);
    }
}
