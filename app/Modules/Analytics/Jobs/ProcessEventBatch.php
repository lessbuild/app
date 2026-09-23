<?php

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Actions\Collection\RebuildSiteVisits;
use App\Modules\Analytics\Actions\Goals\RebuildGoalConversions;
use App\Modules\Analytics\Actions\Reporting\RebuildReportAggregates;
use App\Modules\Analytics\Enums\IngestionStatus;
use App\Modules\Analytics\Models\IngestionBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessEventBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $batchId)
    {
        $this->connection = 'analytics';
        $this->queue = 'analytics';
    }

    public function handle(
        RebuildSiteVisits $rebuildSiteVisits,
        RebuildGoalConversions $rebuildGoalConversions,
        RebuildReportAggregates $rebuildReportAggregates,
    ): void {
        DB::connection('analytics')->transaction(function () use ($rebuildSiteVisits, $rebuildGoalConversions, $rebuildReportAggregates): void {
            $batch = IngestionBatch::query()->lockForUpdate()->find($this->batchId);

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

    public function failed(?\Throwable $exception): void
    {
        IngestionBatch::query()->whereKey($this->batchId)->update([
            'status' => IngestionStatus::Failed->value,
            'failure_message' => str($exception?->getMessage())->limit(500)->toString(),
        ]);
    }
}
