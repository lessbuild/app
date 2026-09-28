<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\CancelConfigurationOperation;
use App\Actions\Deploy\RetryConfigurationOperation;
use App\Models\ConfigurationApplication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

/** Retry or cancel one of a configuration's deploys (`{action}` is retry or cancel). */
final class UpdateConfigurationOperationController
{
    /**
     * Retries or cancels one of an applied configuration's operations.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ConfigurationApplication  $application
     * @param  string  $operation
     * @param  string  $action
     * @param  RetryConfigurationOperation  $retry
     * @param  CancelConfigurationOperation  $cancel
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ConfigurationApplication $application, string $operation, string $action, RetryConfigurationOperation $retry, CancelConfigurationOperation $cancel): RedirectResponse
    {
        $record = $application->relatedOperations()->findOrFail((int) $operation);
        if ($action === 'retry') {
            $retry->handle($user, $application, $record);
        } else {
            $cancel->handle($user, $application, $record);
        }

        return to_route('deploy.configuration.applications.show', [$project, $application->id])->with('status', $action === 'retry' ? __('Deploy retried.') : __('Deploy canceled.'));
    }
}
