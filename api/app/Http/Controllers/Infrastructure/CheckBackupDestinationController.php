<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CheckBackupDestination;
use App\Models\BackupDestination;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class CheckBackupDestinationController
{
    /**
     * Check the destination's bucket can be written and shows the result.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  BackupDestination  $backupDestination
     * @param  CheckBackupDestination  $check
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, BackupDestination $backupDestination, CheckBackupDestination $check): JsonResponse
    {
        $error = $check->handle($user, $backupDestination);
        if ($error !== null) {
            throw ValidationException::withMessages(['destination' => __('The check failed: :error', ['error' => $error])]);
        }

        return response()->json(['redirect' => route('infrastructure.backups', $project, false), 'message' => __('The destination works.')]);
    }
}
