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

        $result = DB::connection('analytics')->transaction(function () use ($site, $events, $batchId, $receivedAt): array {
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

            $accepted = DB::connection('analytics')->table('analytics_events')->insertOrIgnore($rows);

            if ($accepted === 0) {
                $batch->delete();

                return ['batch' => null, 'accepted' => 0];
            }

            if ($accepted !== count($events)) {
                $batch->forceFill(['event_count' => $accepted])->save();
            }

            if ($site->last_event_at === null || $receivedAt->greaterThan($site->last_event_at)) {
                $site->forceFill(['last_event_at' => $receivedAt])->save();
            }

            return ['batch' => $batch, 'accepted' => $accepted];
        });

        if (! $result['batch'] instanceof IngestionBatch) {
            return ['batch_id' => null, 'accepted' => 0];
        }

        /** @var IngestionBatch $batch */
        $batch = $result['batch'];
        ProcessEventBatch::dispatch($batch->id)->afterCommit();

        return ['batch_id' => $batchId, 'accepted' => $result['accepted']];
    }
}
