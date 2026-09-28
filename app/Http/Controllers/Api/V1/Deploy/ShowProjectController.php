<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/v1/projects/{project}`. */
final class ShowProjectController
{
    /**
     * Returns one project with its environments.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $project
     * @param  DeployApiQuery  $query
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, #[TokenAccount] Account $account, string $project, DeployApiQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->projectData($query->project($user, $account, $project))]);
    }
}
