<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Actions\Deploy\ApplyConfigurationReview;
use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\ConfigurationQuery;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `POST /api/v1/projects/{project}/configuration/reviews/{review}/apply`: apply it (again returns the same receipt). */
final class ApplyConfigurationReviewController
{
    /**
     * Apply the review and returns the application's receipt.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $project
     * @param  string  $review
     * @param  DeployApiQuery  $query
     * @param  ConfigurationQuery  $configuration
     * @param  ApplyConfigurationReview  $apply
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, #[TokenAccount] Account $account, string $project, string $review, DeployApiQuery $query, ConfigurationQuery $configuration, ApplyConfigurationReview $apply): JsonResponse
    {
        $record = $query->project($user, $account, $project)->configurationReviews()->findOrFail((int) $review);

        return response()->json(['data' => $configuration->receipt($apply->handle($user, $record))]);
    }
}
