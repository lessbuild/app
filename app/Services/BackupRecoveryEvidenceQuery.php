<?php

namespace App\Services;

use App\Data\BackupRecoverySummary;
use App\Models\BackupRestore;
use App\Models\BackupRestoreVerification;
use App\Models\Organization;
use App\Models\WebsiteBackup;
use Illuminate\Database\Eloquent\Collection;

class BackupRecoveryEvidenceQuery
{
    /**
     * Load the bounded, current-workspace backup history used by the dashboard.
     *
     * @return Collection<int, WebsiteBackup> Recent workspace backup records with
     *                                        related website, destination and restore history.
     */
    public function recentBackups(Organization $organization): Collection
    {
        return WebsiteBackup::query()
            ->whereHas('website', fn ($query) => $query->where('organization_id', $organization->id))
            ->with(['website', 'destination', 'restores', 'verifications'])
            ->latest()
            ->limit(50)
            ->get();
    }

    /**
     * Select recovery evidence independently of the bounded history window.
     *
     * Successful managed backups require a stored snapshot and completion
     * time. HTTPS evidence remains a transport fact, successful restore rows
     * remain in-place restore evidence, and isolated verification is reported
     * separately only after its integrity, smoke, and cleanup checks succeed.
     */
    public function summary(Organization $organization): BackupRecoverySummary
    {
        $backupScope = WebsiteBackup::query()
            ->whereHas('website', fn ($query) => $query->where('organization_id', $organization->id))
            ->where('status', WebsiteBackup::STATUS_SUCCEEDED)
            ->whereNotNull('snapshot_id')
            ->whereNotNull('completed_at');

        $latestBackup = (clone $backupScope)
            ->latest('completed_at')
            ->latest('id')
            ->first(['completed_at']);

        $latestTransport = (clone $backupScope)
            ->whereNotNull('https_verified_at')
            ->latest('https_verified_at')
            ->latest('id')
            ->first(['https_verified_at']);

        $latestRestore = BackupRestore::query()
            ->whereHas('backup', function ($query) use ($organization): void {
                $query
                    ->whereHas('website', fn ($websiteQuery) => $websiteQuery->where('organization_id', $organization->id))
                    ->where('status', WebsiteBackup::STATUS_SUCCEEDED)
                    ->whereNotNull('snapshot_id')
                    ->whereNotNull('completed_at');
            })
            ->where('status', BackupRestore::STATUS_SUCCEEDED)
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->latest('id')
            ->first(['started_at', 'completed_at']);

        $latestVerification = BackupRestoreVerification::query()
            ->whereHas('backup', function ($query) use ($organization): void {
                $query
                    ->whereHas('website', fn ($websiteQuery) => $websiteQuery->where('organization_id', $organization->id))
                    ->where('status', WebsiteBackup::STATUS_SUCCEEDED)
                    ->whereNotNull('snapshot_id')
                    ->whereNotNull('completed_at');
            })
            ->where('status', BackupRestoreVerification::STATUS_SUCCEEDED)
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->latest('id')
            ->first(['completed_at']);

        return new BackupRecoverySummary(
            latestBackupCompletedAt: $latestBackup?->completed_at,
            latestTransportVerifiedAt: $latestTransport?->https_verified_at,
            latestRestoreCompletedAt: $latestRestore?->completed_at,
            latestRestoreSeconds: $latestRestore?->started_at && $latestRestore?->completed_at
                ? $latestRestore->started_at->diffInSeconds($latestRestore->completed_at)
                : null,
            latestIndependentRecoveryVerificationAt: $latestVerification?->completed_at,
        );
    }
}
