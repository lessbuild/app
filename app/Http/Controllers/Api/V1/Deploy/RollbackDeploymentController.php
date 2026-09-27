<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Actions\Deploy\RollbackBuild;
use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `POST /api/v1/deployments/{build}/rollback`: make that build's retained release live again. */
final class RollbackDeploymentController
{
    public function __invoke(#[CurrentUser] User $user, #[TokenAccount] Account $account, string $build, DeployApiQuery $query, RollbackBuild $rollback): JsonResponse
    {
        $rolledBack = $rollback->handle($user, $query->build($user, $account, $build));

        return response()->json(['data' => ['status' => 'queued', 'deployment' => $query->buildData($rolledBack)]], 202);
    }
}
