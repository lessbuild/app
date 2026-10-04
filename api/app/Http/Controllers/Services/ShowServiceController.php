<?php

declare(strict_types=1);

namespace App\Http\Controllers\Services;

use App\Data\Projects\ServiceOption;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use App\Platform\ServiceRegistry;
use App\Queries\Projects\ServiceProjectsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/services/{service}`. */
final class ShowServiceController
{
    /**
     * Return a service across the account: what it is, and each project with whether it uses the service and whether
     * the person may turn it on there.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $service
     * @param  ServiceRegistry  $services
     * @param  ServiceProjectsQuery  $query
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $service, ServiceRegistry $services, ServiceProjectsQuery $query): JsonResponse
    {
        $definition = $services->find($service) ?? abort(404);

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'service' => ServiceOption::from($definition),
            'projects' => $query->handle($account, $service, $user),
            'canCreateProject' => $user->can('create', [Project::class, $account]),
        ]);
    }
}
