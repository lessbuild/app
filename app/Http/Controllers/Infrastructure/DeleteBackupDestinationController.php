<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteBackupDestination;
use App\Models\BackupDestination;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteBackupDestinationController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, BackupDestination $backupDestination, DeleteBackupDestination $delete): RedirectResponse
    {
        $delete->handle($user, $backupDestination);

        return to_route('infrastructure.backups', $project)->with('status', __('Destination removed.'));
    }
}
