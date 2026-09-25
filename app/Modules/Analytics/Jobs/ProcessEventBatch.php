<?php

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Actions\Collection\RebuildSiteVisits;
use App\Modules\Analytics\Actions\Goals\RebuildGoalConversions;
use App\Modules\Analytics\Actions\Reporting\RebuildReportAggregates;
use App\Modules\Analytics\Enums\IngestionStatus;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
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
            $candidate = IngestionBatch::query()->find($this->batchId);
            if (! $candidate) {
                return;
            }
            $site = Site::query()->withTrashed()->find($candidate->site_id);
            if (! $site) {
                IngestionBatch::query()->whereKey($this->batchId)->where('status', IngestionStatus::Pending->value)->update([
                    'status' => IngestionStatus::Failed->value,
                    'failure_message' => 'The Analytics site is no longer available.',
                    'updated_at' => now(),
                ]);

                return;
            }
            DB::connection('analytics')->table('workspaces')->where('id', $site->workspace_id)->update(['id' => DB::raw('id')]);
            $workspace = Workspace::query()->whereKey($site->workspace_id)->lockForUpdate()->first();
            $batch = IngestionBatch::query()->lockForUpdate()->find($this->batchId);

            if (! $batch || in_array($batch->status, [IngestionStatus::Processed->value, IngestionStatus::Failed->value], true)) {
                return;
            }
            if (! $workspace || app(AnalyticsDeletionFence::class)->isFenced('workspace', (string) $workspace->getKey())) {
                $batch->update([
                    'status' => IngestionStatus::Failed->value,
                    'failure_message' => 'The Analytics workspace is being deleted.',
                ]);

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
