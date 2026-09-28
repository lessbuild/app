<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Actions\Deploy\ScaleEnvironment;
use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `PATCH /api/v1/environments/{environment}/scale` with `replicas`: how many replicas of each worker run, within the environment's range. */
final class ScaleEnvironmentController
{
    /**
     * Sets the replica count, validated against the environment's minimum and maximum (202).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $environment
     * @param  DeployApiQuery  $query
     * @param  ScaleEnvironment  $scale
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, #[TokenAccount] Account $account, string $environment, DeployApiQuery $query, ScaleEnvironment $scale): JsonResponse
    {
        $target = $query->environment($user, $account, $environment);
        $request->validate(['replicas' => ['required', 'integer', 'min:'.$target->minimum_replicas, 'max:'.$target->maximum_replicas]]);
        $scale->handle($user, $target, $request->integer('replicas'));

        return response()->json(['data' => ['desired_replicas' => $request->integer('replicas'), 'status' => 'queued']], 202);
    }
}
