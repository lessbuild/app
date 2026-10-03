<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Jobs\Analytics\ProcessEventBatch;
use App\Models\AnalyticsIngestionBatch;

final class DispatchPendingBatches
{
    /**
     * Queue batches whose processing never started or failed (after a short grace period). Returns how many.
     *
     * @param  int  $limit
     * @return int
     */
    public function handle(int $limit = 500): int
    {
        $ids = AnalyticsIngestionBatch::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('accepted_at', '<', now()->subSeconds(10))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        foreach ($ids as $id) {
            ProcessEventBatch::dispatch((int) $id);
        }

        return $ids->count();
    }
}
