<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\CancelConfigurationOperation;
use App\Actions\Deploy\RetryConfigurationOperation;
use App\Models\ConfigurationApplication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** Retry or cancel one of a configuration's deploys (`{action}` is retry or cancel). */
final class UpdateConfigurationOperationController
{
    /**
     * Retry or cancels one of an applied configuration's operations.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ConfigurationApplication  $application
     * @param  string  $operation
     * @param  string  $action
     * @param  RetryConfigurationOperation  $retry
     * @param  CancelConfigurationOperation  $cancel
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ConfigurationApplication $application, string $operation, string $action, RetryConfigurationOperation $retry, CancelConfigurationOperation $cancel): JsonResponse
    {
        $record = $application->relatedOperations()->findOrFail((int) $operation);
        if ($action === 'retry') {
            $retry->handle($user, $application, $record);
        } else {
            $cancel->handle($user, $application, $record);
        }

        return response()->json(['redirect' => route('deploy.configuration.applications.show', [$project, $application->id], false), 'message' => $action === 'retry' ? __('Deploy retried.') : __('Deploy canceled.')]);
    }
}
