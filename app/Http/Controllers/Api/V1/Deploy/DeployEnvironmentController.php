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

/** `POST /api/v1/environments/{environment}/deploy`: deploy the environment's repository (the first one, if it has several). */
final class DeployEnvironmentController
{
    /**
     * Queues a deploy of the environment's first repository (202), or 422 when it has none.
     */
    public function __invoke(#[CurrentUser] User $user, #[TokenAccount] Account $account, string $environment, DeployApiQuery $query, DeployRepository $deploy): JsonResponse
    {
        $repository = $query->environment($user, $account, $environment)->repositories()->orderBy('id')->first();
        abort_if($repository === null, 422, 'This environment has no repository.');

        return response()->json(['data' => $query->buildData($deploy->handle($user, $repository, null, 'api'))], 202);
    }
}
