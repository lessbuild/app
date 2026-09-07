<?php

namespace App\Console\Commands;

use App\Jobs\Web\CreateWebsiteBackupJob;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Models\WebsiteBackupSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunWebsiteBackupsCommand extends Command
{
    protected $signature = 'buildpusher:backups:run';

    protected $description = 'Queue due managed website backups';

    /**
     * Lock and recheck due backup schedules, create queued backup records, and dispatch their workers.
     *
     * @return int SUCCESS after dispatching the due backups.
     */
    public function handle(): int
    {
        $queued = 0;
        WebsiteBackupSchedule::query()
            ->with(['website', 'destination'])
            ->where('is_active', true)
            ->whereHas('destination', fn ($query) => $query->where('is_active', true))
            ->whereHas('website', fn ($query) => $query->where('provisioning_status', Website::STATUS_ACTIVE))
            ->orderBy('id')
            ->eachById(function (WebsiteBackupSchedule $schedule) use (&$queued): void {
                if (! $this->due($schedule)) {
                    return;
                }
                $backup = DB::transaction(function () use ($schedule): ?WebsiteBackup {
                    $locked = WebsiteBackupSchedule::query()->lockForUpdate()->find($schedule->id);
                    if (! $locked || ! $this->due($locked)) {
                        return null;
                    }
                    $locked->update(['last_queued_at' => now()]);

                    return $locked->backups()->create([
                        'website_id' => $locked->website_id,
                        'backup_destination_id' => $locked->backup_destination_id,
                        'status' => WebsiteBackup::STATUS_QUEUED,
                    ]);
                });
                if ($backup) {
                    CreateWebsiteBackupJob::dispatch($backup->id);
                    $queued++;
                }
            });
        $this->info("Queued {$queued} website backup(s).");

        return self::SUCCESS;
    }

    /**
     * Compare the schedule's run time and daily or weekly recurrence with its last queued slot.
     *
     * @param  WebsiteBackupSchedule  $schedule  Backup recurrence and last-queued timestamp to evaluate.
     * @return bool Whether the current time has reached an unclaimed backup slot.
     */
    private function due(WebsiteBackupSchedule $schedule): bool
    {
        $now = now();
        $scheduled = $now->copy()->setTimeFromTimeString($schedule->run_at);
        if ($now->lt($scheduled) || $schedule->last_queued_at?->gte($scheduled)) {
            return false;
        }

        return $schedule->frequency === 'daily'
            || ($schedule->frequency === 'weekly' && $now->dayOfWeek === $schedule->weekday);
    }
}
