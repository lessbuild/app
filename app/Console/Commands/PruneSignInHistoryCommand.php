<?php

namespace App\Console\Commands;

use App\Models\SignInEvent;
use Illuminate\Console\Command;

class PruneSignInHistoryCommand extends Command
{
    protected $signature = 'lessbuild:sign-ins:prune
        {--days= : Retain successful sign-in records for this many days}';

    protected $description = 'Prune successful sign-in history older than the configured retention window';

    public function handle(): int
    {
        $days = $this->retentionDays();
        if ($days === null) {
            $this->error('Retention days must be a positive integer.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;
        SignInEvent::query()
            ->where('signed_in_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(250, function ($events) use (&$deleted, $cutoff): void {
                $deleted += SignInEvent::query()
                    ->whereKey($events->modelKeys())
                    ->where('signed_in_at', '<', $cutoff)
                    ->delete();
            });

        $this->info("Pruned {$deleted} successful sign-in record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    private function retentionDays(): ?int
    {
        $value = $this->option('days');
        $value = $value === null || $value === ''
            ? config('lessbuild.sign_in_retention_days')
            : $value;
        $days = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $days === false ? null : $days;
    }
}
