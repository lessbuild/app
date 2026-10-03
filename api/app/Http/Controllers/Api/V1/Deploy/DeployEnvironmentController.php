<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Actions\Deploy\DeployRepository;
use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `POST /api/v1/environments/{environment}/deploy`: deploy the environment's repository (the first one, if it has several). */
final class DeployEnvironmentController
{
    /**
     * Queue a deploy of the environment's first repository (202), or 422 when it has none. An optional `ref` deploys a
     * branch, tag or commit instead of the repository's branch.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $environment
     * @param  DeployApiQuery  $query
     * @param  DeployRepository  $deploy
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, #[TokenAccount] Account $account, string $environment, DeployApiQuery $query, DeployRepository $deploy): JsonResponse
    {
        $repository = $query->environment($user, $account, $environment)->repositories()->orderBy('id')->first();
        abort_if($repository === null, 422, 'This environment has no repository.');

        return response()->json(['data' => $query->buildData($deploy->handle($user, $repository, null, 'api', is_string($request->input('ref')) ? $request->input('ref') : null))], 202);
    }
}
