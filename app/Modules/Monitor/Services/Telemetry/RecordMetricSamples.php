<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\MetricSeries;
use Carbon\CarbonImmutable;

final class RecordMetricSamples
{
    /**
     * Called inside the receipt transaction with application/environment locks held.
     *
     * @param  list<array{projection: array<string, mixed>, event_id: int}>  $points
     */
    public function record(Environment $environment, array $points, CarbonImmutable $receivedAt): void
    {
        $groups = collect($points)->groupBy('projection.series.identity_hash');
        foreach ($groups->chunk(300) as $chunk) {
            $seriesByHash = MetricSeries::query()->where('environment_id', $environment->id)->whereIn('identity_hash', $chunk->keys())->get()->keyBy('identity_hash');
            foreach ($chunk as $hash => $items) {
                $series = $seriesByHash->get($hash) ?? MetricSeries::query()->create([
                    ...$items->first()['projection']['series'], 'environment_id' => $environment->id,
                    'first_received_at' => $receivedAt, 'last_received_at' => $receivedAt,
                ]);
                $samples = $series->samples()->whereIn('time_key', $items->pluck('projection.sample.time_key')->unique()->all())->get()->keyBy('time_key');
                foreach ($items as $item) {
                    $data = $item['projection']['sample'];
                    $sample = $samples->get($data['time_key']);
                    if ($sample === null) {
                        $sample = $series->samples()->create([...$data, 'telemetry_event_id' => $item['event_id']]);
                        $samples->put($data['time_key'], $sample);
                    } elseif (! hash_equals($sample->value_hash, $data['value_hash']) && $sample->state !== 'conflict') {
                        $sample->forceFill(['state' => 'conflict', 'value_text' => null, 'value' => null])->save();
                    }
                }
                $series->forceFill([
                    'first_received_at' => $series->first_received_at->min($receivedAt),
                    'last_received_at' => $series->last_received_at->max($receivedAt),
                ])->save();
            }
        }
    }
}
