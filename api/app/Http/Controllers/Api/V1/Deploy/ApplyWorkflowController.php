<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Actions\Deploy\ApplyWorkflow;
use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `PUT /api/v1/projects/{project}/workflow` with `workflow`: apply Deployer's version 1 workflow document. */
final class ApplyWorkflowController
{
    /**
     * Apply the workflow to the project's environments, all or nothing.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $project
     * @param  DeployApiQuery  $query
     * @param  ApplyWorkflow  $apply
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, #[TokenAccount] Account $account, string $project, DeployApiQuery $query, ApplyWorkflow $apply): JsonResponse
    {
        $target = $query->project($user, $account, $project);
        $request->validate(['workflow' => ['required', 'string', 'max:50000']]);
        $apply->handle($user, $target, $request->string('workflow')->toString());

        return response()->json(['data' => ['status' => 'applied']]);
    }
}
