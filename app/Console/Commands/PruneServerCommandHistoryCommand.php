<?php

namespace App\Console\Commands;

use App\Models\ServerCommandExecution;
use Illuminate\Console\Command;

class PruneServerCommandHistoryCommand extends Command
{
    protected $signature = 'lessbuild:commands:prune
        {--days= : Retain terminal server command history for this many days}';

    protected $description = 'Prune expired terminal server command history while preserving active commands';

    public function handle(): int
    {
        $days = $this->retentionDays();
        if ($days === null) {
            $this->error('Retention days must be a positive integer.');

            return self::FAILURE;
        }

        $deleted = 0;
        ServerCommandExecution::query()
            ->whereIn('status', ServerCommandExecution::TERMINAL_STATUSES)
            ->where('created_at', '<', now()->subDays($days))
            ->orderBy('id')
            ->chunkById(250, function ($executions) use (&$deleted): void {
                $deleted += ServerCommandExecution::query()
                    ->whereKey($executions->modelKeys())
                    ->whereIn('status', ServerCommandExecution::TERMINAL_STATUSES)
                    ->delete();
            });

        $this->info("Pruned {$deleted} server command record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    private function retentionDays(): ?int
    {
        $value = $this->option('days');
        $value = $value === null || $value === ''
            ? config('lessbuild.server_command_retention_days')
            : $value;
        $days = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $days === false ? null : $days;
    }
}
