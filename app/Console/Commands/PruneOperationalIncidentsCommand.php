<?php

namespace App\Console\Commands;

use App\Models\OperationalIncident;
use Illuminate\Console\Command;

class PruneOperationalIncidentsCommand extends Command
{
    protected $signature = 'buildpusher:operational-incidents:prune {--days=365}';

    protected $description = 'Delete resolved private operational incidents after the retention period';

    /**
     * Delete resolved operational incidents older than the bounded retention period, preserving open incidents.
     *
     * @return int SUCCESS after pruning resolved history.
     */
    public function handle(): int
    {
        $days = max(30, min(3650, (int) $this->option('days')));
        $deleted = OperationalIncident::query()->where('status', OperationalIncident::STATUS_RESOLVED)->where('resolved_at', '<', now()->subDays($days))->delete();
        $this->info("Pruned {$deleted} operational incident(s).");

        return self::SUCCESS;
    }
}
