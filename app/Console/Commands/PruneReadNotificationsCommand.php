<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class PruneReadNotificationsCommand extends Command
{
    protected $signature = 'lessbuild:notifications:prune
        {--days= : Retain read notifications for this many days after review}';

    protected $description = 'Prune expired read notifications while preserving every unread notification';

    /**
     * Delete old read notifications in batches and recheck their eligibility before deletion, preserving unread notifications.
     *
     * @return int SUCCESS after pruning, or FAILURE when retention is not a positive integer.
     */
    public function handle(): int
    {
        $days = $this->retentionDays();
        if ($days === null) {
            $this->error('Retention days must be a positive integer.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;
        DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(250, function ($notifications) use (&$deleted, $cutoff): void {
                $deleted += DatabaseNotification::query()
                    ->whereKey($notifications->modelKeys())
                    ->whereNotNull('read_at')
                    ->where('read_at', '<', $cutoff)
                    ->delete();
            });

        $this->info("Pruned {$deleted} read notification(s) reviewed more than {$days} day(s) ago.");

        return self::SUCCESS;
    }

    /**
     * Resolve the optional days argument against the configured retention and require a positive integer.
     *
     * @return int|null Validated retention in days, or null when the option or configured default is invalid.
     */
    private function retentionDays(): ?int
    {
        $value = $this->option('days');
        $value = $value === null || $value === ''
            ? config('lessbuild.notification_retention_days')
            : $value;
        $days = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $days === false ? null : $days;
    }
}
