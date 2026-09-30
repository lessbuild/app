<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\VerifyWebsiteBackup;
use App\Models\BackupVerification;
use App\Models\WebsiteBackup;
use App\Models\WebsiteBackupSchedule;

final class RunRestoreDrills
{
    /**
     * Start this month's restore drills: for each website whose backup schedule asks for them, restore its latest
     * successful backup into a scratch area and check it (the same checks as Verify), unless one was checked in the
     * last thirty days. Returns how many started.
     *
     * @return int
     */
    public function handle(): int
    {
        $started = 0;
        $websites = WebsiteBackupSchedule::query()->where('monthly_drill', true)->distinct()->pluck('website_id');
        foreach ($websites as $websiteId) {
            $recent = BackupVerification::query()->whereIn('website_backup_id', WebsiteBackup::query()->where('website_id', $websiteId)->select('id'))
                ->where('created_at', '>=', now()->subDays(30))->exists();
            $latest = WebsiteBackup::query()->where('website_id', $websiteId)->where('status', WebsiteBackup::STATUS_SUCCEEDED)->whereNotNull('snapshot_id')->latest('id')->first();
            if ($recent || $latest === null || ! $latest->isRestorable()) {
                continue;
            }
            $verification = new BackupVerification;
            $verification->forceFill(['website_backup_id' => $latest->id, 'requested_by' => null, 'snapshot_id' => strtolower((string) $latest->snapshot_id), 'status' => 'queued'])->save();
            VerifyWebsiteBackup::dispatch($verification->id);
            $started++;
        }

        return $started;
    }
}
