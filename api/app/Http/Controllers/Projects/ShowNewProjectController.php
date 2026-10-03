<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Data\Projects\ServiceOption;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use App\Platform\ServiceRegistry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/new`. */
final class ShowNewProjectController
{
    /**
     * Return what the new-project wizard offers: the services, and whether the person may create projects here.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  ServiceRegistry  $services
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, #[CurrentAccount] Account $account, ServiceRegistry $services): JsonResponse
    {
        abort_unless($user->can('create', [Project::class, $account]), 403);

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'services' => array_map(ServiceOption::from(...), array_values(array_filter($services->all(), fn ($service): bool => $user->can('useService', [$account, $service->key()])))),
        ]);
    }
}
