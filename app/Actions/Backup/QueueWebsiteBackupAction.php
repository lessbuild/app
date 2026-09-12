<?php

namespace App\Actions\Backup;

use App\Jobs\Web\CreateWebsiteBackupJob;
use App\Models\BackupDestination;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteBackup;

class QueueWebsiteBackupAction
{
    /**
     * Create and dispatch a manual backup unless another backup is already active for the website.
     *
     * @return ?WebsiteBackup The queued record, or null when an existing backup is active.
     */
    public function handle(Website $website, BackupDestination $destination, User $actor): ?WebsiteBackup
    {
        if ($website->backups()->whereIn('status', [WebsiteBackup::STATUS_QUEUED, WebsiteBackup::STATUS_RUNNING])->exists()) {
            return null;
        }

        $backup = $website->backups()->create([
            'backup_destination_id' => $destination->id,
            'triggered_by' => $actor->id,
            'status' => WebsiteBackup::STATUS_QUEUED,
        ]);
        CreateWebsiteBackupJob::dispatch($backup->id);

        return $backup;
    }
}
