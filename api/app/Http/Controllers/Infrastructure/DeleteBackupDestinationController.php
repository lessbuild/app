<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteBackupDestination;
use App\Models\BackupDestination;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteBackupDestinationController
{
    /**
     * Remove a backup destination.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  BackupDestination  $backupDestination
     * @param  DeleteBackupDestination  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, BackupDestination $backupDestination, DeleteBackupDestination $delete): JsonResponse
    {
        $delete->handle($user, $backupDestination);

        return response()->json(['redirect' => route('infrastructure.backups', $project, false), 'message' => __('Destination removed.')]);
    }
}
