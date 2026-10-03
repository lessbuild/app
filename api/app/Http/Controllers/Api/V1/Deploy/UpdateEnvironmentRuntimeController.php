<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Actions\Deploy\SetEnvironmentRuntime;
use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `PATCH /api/v1/environments/{environment}/runtime` with `state` (`running` or `hibernated`): wake or hibernate it. */
final class UpdateEnvironmentRuntimeController
{
    /**
     * Queue the environment to hibernate or run (202), as Deployer answered.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $environment
     * @param  DeployApiQuery  $query
     * @param  SetEnvironmentRuntime  $set
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, #[TokenAccount] Account $account, string $environment, DeployApiQuery $query, SetEnvironmentRuntime $set): JsonResponse
    {
        $target = $query->environment($user, $account, $environment);
        /** @var array{state: 'running'|'hibernated'} $data */
        $data = $request->validate(['state' => ['required', 'in:running,hibernated']]);
        $set->handle($user, $target, $data['state']);

        return response()->json(['data' => ['status' => 'queued', 'state' => $data['state']]], 202);
    }
}
