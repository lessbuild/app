<?php

namespace App\Actions\Backup;

use App\Exceptions\BackupRestoreException;
use App\Jobs\Web\RestoreWebsiteBackupJob;
use App\Models\BackupRestore;
use App\Models\User;
use App\Models\WebsiteBackup;
use Illuminate\Support\Facades\DB;

class RequestWebsiteBackupRestoreAction
{
    /**
     * Queue a restore from a completed backup after applying the current deployment safety check.
     *
     * @throws BackupRestoreException If the backup is incomplete or a deployment is active.
     */
    public function handle(WebsiteBackup $backup, User $actor): BackupRestore
    {
        if ($backup->status !== WebsiteBackup::STATUS_SUCCEEDED || ! $backup->snapshot_id) {
            throw new BackupRestoreException('Only completed backups can be restored.');
        }

        if ($backup->website->hasActiveDeployment()) {
            throw new BackupRestoreException('Wait for the active deployment to finish before restoring.');
        }

        $restore = DB::transaction(fn (): BackupRestore => $backup->restores()->create([
            'requested_by' => $actor->id,
            'status' => BackupRestore::STATUS_QUEUED,
        ]));
        RestoreWebsiteBackupJob::dispatch($restore->id);

        return $restore;
    }
}
