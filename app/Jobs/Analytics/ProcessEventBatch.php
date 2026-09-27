<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\RebuildGoalConversions;
use App\Actions\Analytics\RebuildReportAggregates;
use App\Actions\Analytics\RebuildSiteVisits;
use App\Enums\IngestionStatus;
use App\Models\AnalyticsIngestionBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessEventBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Rebuilding aggregates can hit a lock or deadlock, so it gets three tries.
     */
    public int $tries = 3;

    /**
     * Folds a batch of collected analytics events into the site's visits, goal conversions and report aggregates.
     *
     * @param  int  $batchId  The ingestion batch.
     */
    public function __construct(public int $batchId) {}

    /**
     * Rebuilds everything the batch touches in one transaction, marks it processed, and moves the site's "last
     * processed" time forward.
     */
    public function handle(
        RebuildSiteVisits $rebuildSiteVisits,
        RebuildGoalConversions $rebuildGoalConversions,
        RebuildReportAggregates $rebuildReportAggregates,
    ): void {
        DB::transaction(function () use ($rebuildSiteVisits, $rebuildGoalConversions, $rebuildReportAggregates): void {
            $batch = AnalyticsIngestionBatch::query()->lockForUpdate()->find($this->batchId);

            if (! $batch || $batch->status === IngestionStatus::Processed->value) {
                return;
            }

            $batch->update(['status' => IngestionStatus::Processing->value]);
            $rebuildSiteVisits->handle($batch->site, $batch);
            $rebuildGoalConversions->handle($batch->site, $batch);
            $rebuildReportAggregates->handle($batch->site, $batch);
            $batch->update([
                'status' => IngestionStatus::Processed->value,
                'processed_at' => now(),
                'failure_message' => null,
            ]);
            $site = $batch->site;
            if ($site->last_processed_at === null || $batch->processed_at?->greaterThan($site->last_processed_at)) {
                $site->forceFill(['last_processed_at' => $batch->processed_at])->save();
            }
        });
    }

    /**
     * Marks the batch failed with the reason.
     */
    public function failed(?Throwable $exception): void
    {
        AnalyticsIngestionBatch::query()->whereKey($this->batchId)->update([
            'status' => IngestionStatus::Failed->value,
            'failure_message' => str($exception?->getMessage())->limit(500)->toString(),
        ]);
    }
}
