<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Exceptions\StateConflict;
use App\Jobs\Infrastructure\CreateWebsiteBackup;
use App\Models\BackupDestination;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Models\WebsiteBackupSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class QueueWebsiteBackup
{
    /**
     * Queue a backup now (`$actor` set) or for a schedule. Returns null when one is already queued or running for the
     * website, since two at once would fight over the restic repository.
     */
    public function handle(Website $website, BackupDestination $destination, ?User $actor = null, ?WebsiteBackupSchedule $schedule = null): ?WebsiteBackup
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('backUp', $website);
        }
        StateConflict::unless($destination->account_id === $website->account_id, 'The destination belongs to another account.');

        return DB::transaction(function () use ($website, $destination, $actor, $schedule): ?WebsiteBackup {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            if ($locked->provisioning_status !== Website::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['backup' => __('Only live websites can be backed up.')]);
            }
            if ($locked->backups()->whereIn('status', [WebsiteBackup::STATUS_QUEUED, WebsiteBackup::STATUS_RUNNING])->exists()) {
                return null;
            }
            $backup = new WebsiteBackup;
            $backup->forceFill([
                'website_id' => $locked->id, 'backup_destination_id' => $destination->id, 'website_backup_schedule_id' => $schedule?->id,
                'triggered_by' => $actor?->id, 'status' => WebsiteBackup::STATUS_QUEUED,
            ])->save();
            $schedule?->forceFill(['last_queued_at' => now()])->save();
            CreateWebsiteBackup::dispatch($backup->id)->afterCommit();

            return $backup;
        });
    }
}
