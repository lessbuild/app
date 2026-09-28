<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\ConfigurationApplication;
use App\Models\User;
use App\Queries\Deploy\ConfigurationQuery;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/v1/projects/{project}/configuration/applications/{application}`: the receipt, with its deploys' progress. */
final class ShowConfigurationApplicationController
{
    /**
     * Returns the application's receipt.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $project
     * @param  string  $application
     * @param  DeployApiQuery  $query
     * @param  ConfigurationQuery  $configuration
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, #[TokenAccount] Account $account, string $project, string $application, DeployApiQuery $query, ConfigurationQuery $configuration): JsonResponse
    {
        $reviews = $query->project($user, $account, $project)->configurationReviews()->select('id');

        return response()->json(['data' => $configuration->receipt(ConfigurationApplication::query()->whereIn('configuration_review_id', $reviews)->findOrFail((int) $application))]);
    }
}
