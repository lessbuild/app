<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveBackupDestination;
use App\Http\Requests\Infrastructure\BackupDestinationRequest;
use App\Models\BackupDestination;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateBackupDestinationController
{
    /**
     * Saves a backup destination.
     *
     * @param  BackupDestinationRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  BackupDestination  $backupDestination
     * @param  SaveBackupDestination  $save
     * @return RedirectResponse
     */
    public function __invoke(BackupDestinationRequest $request, #[CurrentUser] User $user, Project $project, BackupDestination $backupDestination, SaveBackupDestination $save): RedirectResponse
    {
        $save->handle($project->account, $user, $request->destination(), $backupDestination);

        return to_route('infrastructure.backups', $project)->with('status', __('Destination saved.'));
    }
}
