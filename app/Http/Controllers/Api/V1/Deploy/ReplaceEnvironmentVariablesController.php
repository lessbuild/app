<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Actions\Deploy\ReplaceEnvironmentVariables;
use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `PUT /api/v1/environments/{environment}/variables` with `variables` (KEY=value lines): replace them all. */
final class ReplaceEnvironmentVariablesController
{
    /**
     * Replace the variables and returns how many there are now.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $environment
     * @param  DeployApiQuery  $query
     * @param  ReplaceEnvironmentVariables  $replace
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, #[TokenAccount] Account $account, string $environment, DeployApiQuery $query, ReplaceEnvironmentVariables $replace): JsonResponse
    {
        $request->validate(['variables' => ['required', 'string', 'max:50000']]);

        return response()->json(['data' => ['status' => 'applied', 'count' => $replace->handle($user, $query->environment($user, $account, $environment), $request->string('variables')->toString())]]);
    }
}
