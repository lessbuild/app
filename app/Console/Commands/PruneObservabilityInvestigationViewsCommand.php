<?php

namespace App\Console\Commands;

use App\Models\ObservabilityInvestigationView;
use Illuminate\Console\Command;

class PruneObservabilityInvestigationViewsCommand extends Command
{
    protected $signature = 'buildpusher:observability:investigations:prune {--limit=500 : Maximum expired views to remove}';

    protected $description = 'Delete expired observability investigation views';

    /** Remove a bounded batch of expired views without touching active records. */
    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $now = now();
        $ids = ObservabilityInvestigationView::query()
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $deleted = $ids->isEmpty()
            ? 0
            : ObservabilityInvestigationView::query()
                ->whereKey($ids)
                ->where('expires_at', '<=', now())
                ->delete();

        $this->info("Pruned {$deleted} expired investigation view(s).");

        return self::SUCCESS;
    }
}
