<?php

namespace App\Modules\Analytics\Actions\Collection;

use App\Modules\Analytics\Data\NormalizedEvent;
use App\Modules\Analytics\Jobs\ProcessEventBatch;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AcceptEventBatch
{
    /**
     * @param  list<NormalizedEvent>  $events
     * @return array{batch_id: ?string, accepted: int}
     */
    public function handle(Site $site, array $events): array
    {
        if ($events === []) {
            return ['batch_id' => null, 'accepted' => 0];
        }

        $batchId = (string) Str::uuid();
        $receivedAt = CarbonImmutable::now();

        $batch = DB::connection('analytics')->transaction(function () use ($site, $events, $batchId, $receivedAt): IngestionBatch {
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

            if ($rows !== []) {
                DB::connection('analytics')->table('analytics_events')->insertOrIgnore($rows);
            }

            if ($site->last_event_at === null || $receivedAt->greaterThan($site->last_event_at)) {
                $site->forceFill(['last_event_at' => $receivedAt])->save();
            }

            return $batch;
        });

        ProcessEventBatch::dispatch($batch->id)->afterCommit();

        return ['batch_id' => $batchId, 'accepted' => count($events)];
    }
}
