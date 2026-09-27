<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/v1/deployments/{build}/log`: the tail of its deployment log. */
final class ShowDeploymentLogController
{
    /**
     * Returns the deploy's log, never cached.
     */
    public function __invoke(#[CurrentUser] User $user, #[TokenAccount] Account $account, string $build, DeployApiQuery $query): JsonResponse
    {
        $record = $query->build($user, $account, $build);

        return response()->json(['data' => ['deployment_id' => $record->id, 'status' => $record->status, 'log' => $record->log ?? '']])->header('Cache-Control', 'no-store, private');
    }
}
