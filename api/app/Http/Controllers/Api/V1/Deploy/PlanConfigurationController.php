<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Actions\Deploy\PlanConfiguration;
use App\Http\Attributes\TokenAccount;
use App\Http\Requests\Deploy\ConfigurationRequest;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `POST /api/v1/projects/{project}/configuration/plan` with `document` and `bindings`: what applying it would change. */
final class PlanConfigurationController
{
    /**
     * Return the plan for the posted document.
     *
     * @param  ConfigurationRequest  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $project
     * @param  DeployApiQuery  $query
     * @param  PlanConfiguration  $plan
     * @return JsonResponse
     */
    public function __invoke(ConfigurationRequest $request, #[CurrentUser] User $user, #[TokenAccount] Account $account, string $project, DeployApiQuery $query, PlanConfiguration $plan): JsonResponse
    {
        return response()->json(['data' => $plan->handle($user, $query->project($user, $account, $project), $request->document(), $request->bindings())]);
    }
}
