<?php

namespace App\Actions\Backup;

use App\Exceptions\BackupDestinationInUseException;
use App\Models\BackupDestination;
use App\Models\WebsiteBackup;

class DeleteBackupDestinationAction
{
    /**
     * Delete a destination only after confirming that no schedules or retained backups reference it.
     *
     * @throws BackupDestinationInUseException If dependent backup records still exist.
     */
    public function handle(BackupDestination $destination): void
    {
        if ($destination->schedules()->exists() || WebsiteBackup::query()->where('backup_destination_id', $destination->id)->exists()) {
            throw new BackupDestinationInUseException;
        }

        $destination->delete();
    }
}
