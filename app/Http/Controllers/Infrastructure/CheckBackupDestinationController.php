<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CheckBackupDestination;
use App\Models\BackupDestination;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class CheckBackupDestinationController
{
    /**
     * Checks the destination's bucket can be written and shows the result.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  BackupDestination  $backupDestination
     * @param  CheckBackupDestination  $check
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, BackupDestination $backupDestination, CheckBackupDestination $check): RedirectResponse
    {
        $error = $check->handle($user, $backupDestination);
        $redirect = to_route('infrastructure.backups', $project);

        return $error === null ? $redirect->with('status', __('The destination works.')) : $redirect->withErrors(['destination' => __('The check failed: :error', ['error' => $error])]);
    }
}
