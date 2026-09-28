<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveBackupDestination;
use App\Http\Requests\Infrastructure\BackupDestinationRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreBackupDestinationController
{
    /**
     * Add a backup destination and suggests checking it.
     *
     * @param  BackupDestinationRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveBackupDestination  $save
     * @return RedirectResponse
     */
    public function __invoke(BackupDestinationRequest $request, #[CurrentUser] User $user, Project $project, SaveBackupDestination $save): RedirectResponse
    {
        $save->handle($project->account, $user, $request->destination());

        return to_route('infrastructure.backups', $project)->with('status', __('Destination added. Check it before the first backup.'));
    }
}
