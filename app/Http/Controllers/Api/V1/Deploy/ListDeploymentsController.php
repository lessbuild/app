<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\Build;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/v1/deployments`: builds, newest first (the latest 100, or cursor pages with `limit`). */
final class ListDeploymentsController
{
    /**
     * Returns the deploys the token can see, newest first.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  DeployApiQuery  $query
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, #[TokenAccount] Account $account, DeployApiQuery $query): JsonResponse
    {
        $page = $query->page($query->builds($user, $account), $request->query('limit'), $request->query('cursor'), 'desc', 100);

        return response()->json(array_filter(['data' => collect($page['items'])->map(fn (Build $build): array => $query->buildData($build))->values(), 'meta' => $page['meta']], fn (mixed $value): bool => $value !== null));
    }
}
