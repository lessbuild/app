<?php

namespace App\Actions\Backup;

use App\Exceptions\BackupRestoreException;
use App\Jobs\Web\VerifyWebsiteBackupJob;
use App\Models\BackupRestoreVerification;
use App\Models\User;
use App\Models\WebsiteBackup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RequestWebsiteBackupVerificationAction
{
    /**
     * Bind an isolated verification to an exact completed snapshot and queue it after the local transaction commits.
     *
     * @throws BackupRestoreException If the snapshot is incomplete, a deployment is active, or another verification is running.
     */
    public function handle(WebsiteBackup $backup, User $actor): BackupRestoreVerification
    {
        $verification = DB::transaction(function () use ($backup, $actor): BackupRestoreVerification {
            $lockedBackup = WebsiteBackup::query()
                ->with('website')
                ->lockForUpdate()
                ->find($backup->id);

            if (! $lockedBackup || $lockedBackup->status !== WebsiteBackup::STATUS_SUCCEEDED
                || preg_match('/\A[a-f0-9]{8,64}\z/D', (string) $lockedBackup->snapshot_id) !== 1) {
                throw new BackupRestoreException('Only completed backups with a valid snapshot can be verified.');
            }

            if ((int) $lockedBackup->website?->organization_id !== (int) $actor->current_organization_id) {
                throw new AuthorizationException;
            }

            if ($lockedBackup->website?->hasActiveDeployment()) {
                throw new BackupRestoreException('Wait for the active deployment to finish before verifying recovery.');
            }

            if ($lockedBackup->verifications()
                ->whereIn('status', [BackupRestoreVerification::STATUS_QUEUED, BackupRestoreVerification::STATUS_RUNNING])
                ->exists()) {
                throw new BackupRestoreException('A recovery verification is already in progress for this backup.');
            }

            return $lockedBackup->verifications()->create([
                'requested_by' => $actor->id,
                'snapshot_id' => strtolower((string) $lockedBackup->snapshot_id),
                'target_type' => BackupRestoreVerification::TARGET_SAME_SERVER_TEMPORARY,
                'overwrite_mode' => BackupRestoreVerification::OVERWRITE_NEVER,
                'status' => BackupRestoreVerification::STATUS_QUEUED,
                'integrity_status' => BackupRestoreVerification::CHECK_PENDING,
                'smoke_status' => BackupRestoreVerification::CHECK_PENDING,
                'cleanup_status' => BackupRestoreVerification::CLEANUP_PENDING,
            ]);
        });

        VerifyWebsiteBackupJob::dispatch($verification->id);

        return $verification;
    }
}
