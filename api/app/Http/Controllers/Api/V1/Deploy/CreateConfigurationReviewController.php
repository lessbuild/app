<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Actions\Deploy\CreateConfigurationReview;
use App\Http\Attributes\TokenAccount;
use App\Http\Requests\Deploy\ConfigurationRequest;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `POST /api/v1/projects/{project}/configuration/reviews`: freeze the plan for 15 minutes. */
final class CreateConfigurationReviewController
{
    /**
     * Create the review and returns its plan and expiry (201).
     *
     * @param  ConfigurationRequest  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $project
     * @param  DeployApiQuery  $query
     * @param  CreateConfigurationReview  $create
     * @return JsonResponse
     */
    public function __invoke(ConfigurationRequest $request, #[CurrentUser] User $user, #[TokenAccount] Account $account, string $project, DeployApiQuery $query, CreateConfigurationReview $create): JsonResponse
    {
        $review = $create->handle($user, $query->project($user, $account, $project), $request->document(), $request->bindings());

        return response()->json(['data' => ['id' => $review->id, 'plan' => $review->summary, 'expires_at' => $review->expires_at->toIso8601String()]], 201);
    }
}
