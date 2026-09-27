<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/v1/deployments/{build}`. */
final class ShowDeploymentController
{
    public function __invoke(#[CurrentUser] User $user, #[TokenAccount] Account $account, string $build, DeployApiQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->buildData($query->build($user, $account, $build))]);
    }
}
