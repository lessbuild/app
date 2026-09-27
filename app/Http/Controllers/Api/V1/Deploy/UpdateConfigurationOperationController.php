<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Actions\Deploy\CancelConfigurationOperation;
use App\Actions\Deploy\RetryConfigurationOperation;
use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\ConfigurationApplication;
use App\Models\User;
use App\Queries\Deploy\ConfigurationQuery;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `POST …/configuration/applications/{application}/operations/{operation}/{cancel|retry}`. */
final class UpdateConfigurationOperationController
{
    /**
     * Cancels or retries the operation and returns the refreshed receipt (with the new operation's ID for retries).
     */
    public function __invoke(#[CurrentUser] User $user, #[TokenAccount] Account $account, string $project, string $application, string $operation, string $action, DeployApiQuery $query, ConfigurationQuery $configuration, RetryConfigurationOperation $retry, CancelConfigurationOperation $cancel): JsonResponse
    {
        $reviews = $query->project($user, $account, $project)->configurationReviews()->select('id');
        $record = ConfigurationApplication::query()->with('review.project')->whereIn('configuration_review_id', $reviews)->findOrFail((int) $application);
        $target = $record->relatedOperations()->findOrFail((int) $operation);
        if ($action === 'retry') {
            $retried = $retry->handle($user, $record, $target);

            return response()->json(['data' => $configuration->receipt($record), 'retry_operation_id' => $retried->id]);
        }
        $cancel->handle($user, $record, $target);

        return response()->json(['data' => $configuration->receipt($record)]);
    }
}
