<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Actions\Deploy\PromoteBuild;
use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `POST /api/v1/deployments/{build}/promote` with `target_environment_id` and an optional `promotion_note`. */
final class PromoteDeploymentController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, #[TokenAccount] Account $account, string $build, DeployApiQuery $query, PromoteBuild $promote): JsonResponse
    {
        $request->validate(['target_environment_id' => ['required', 'string', 'max:26'], 'promotion_note' => ['nullable', 'string', 'max:2000']]);
        $promoted = $promote->handle($user, $query->build($user, $account, $build), $query->environment($user, $account, $request->string('target_environment_id')->toString()), $request->filled('promotion_note') ? $request->string('promotion_note')->toString() : null);

        return response()->json(['data' => ['status' => 'queued', 'deployment' => $query->buildData($promoted)]], 202);
    }
}
