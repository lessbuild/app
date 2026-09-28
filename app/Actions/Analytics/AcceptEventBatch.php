<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Data\Analytics\NormalizedEvent;
use App\Jobs\Analytics\ProcessEventBatch;
use App\Models\AnalyticsIngestionBatch;
use App\Models\AnalyticsSite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AcceptEventBatch
{
    /**
     * Store a batch of collected events and queues it for processing once the transaction commits. Events the site
     * already sent (same event ID) are ignored, so a resent batch doesn't count twice.
     *
     * @param  AnalyticsSite  $site
     * @param  list<NormalizedEvent>  $events
     * @return array{batch_id: ?string, accepted: int}
     */
    public function handle(AnalyticsSite $site, array $events): array
    {
        if ($events === []) {
            return ['batch_id' => null, 'accepted' => 0];
        }

        $batchId = (string) Str::uuid();
        $receivedAt = CarbonImmutable::now();

        $batch = DB::transaction(function () use ($site, $events, $batchId, $receivedAt): AnalyticsIngestionBatch {
            $batch = $site->ingestionBatches()->create([
                'batch_id' => $batchId,
                'event_count' => count($events),
                'status' => 'pending',
                'accepted_at' => $receivedAt,
            ]);

            $rows = array_map(
                fn (NormalizedEvent $event): array => $event->toDatabase($site->id, $batch->id, $receivedAt),
                $events,
            );

            DB::table('analytics_events')->insertOrIgnore($rows);

            if ($site->last_event_at === null || $receivedAt->greaterThan($site->last_event_at)) {
                $site->forceFill(['last_event_at' => $receivedAt])->save();
            }

            return $batch;
        });

        ProcessEventBatch::dispatch($batch->id)->afterCommit();

        return ['batch_id' => $batchId, 'accepted' => count($events)];
    }
}
