<?php

namespace App\Modules\Monitor\Services\Telemetry;

final class TelemetryPayloadGuard
{
    public function assertFiniteNumbers(mixed $value): void
    {
        abort_if(is_float($value) && ! is_finite($value), 400, 'Telemetry JSON numbers must be finite and within the supported numeric range.');

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertFiniteNumbers($item);
            }
        }
    }

    public function assertJsonComplexity(string $body): void
    {
        $limit = (int) config('monitor.beacon.telemetry.max_json_nodes');
        $nodes = 0;
        $quoted = false;
        $escaped = false;
        $length = strlen($body);

        for ($index = 0; $index < $length; $index++) {
            $character = $body[$index];

            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $quoted = false;
                }

                continue;
            }

            if ($character === '"') {
                $quoted = true;
            } elseif (str_contains('{[:,', $character)) {
                $nodes++;
                abort_if($nodes > $limit, 413, 'Telemetry request exceeds the structural complexity limit.');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    public function assertRecordCount(array $payload, string $signal): void
    {
        if ($signal === 'ingest') {
            $this->assertCount(is_array($payload['events'] ?? null) ? count($payload['events']) : 0);

            return;
        }

        [$resourceKey, $scopeKey, $recordKey] = match ($signal) {
            'traces' => ['resourceSpans', 'scopeSpans', 'spans'],
            'logs' => ['resourceLogs', 'scopeLogs', 'logRecords'],
            'metrics' => ['resourceMetrics', 'scopeMetrics', 'metrics'],
            default => [null, null, null],
        };

        if ($resourceKey === null || ! is_array($payload[$resourceKey] ?? null)) {
            return;
        }

        $count = 0;

        foreach ($payload[$resourceKey] as $resource) {
            foreach (is_array($resource[$scopeKey] ?? null) ? $resource[$scopeKey] : [] as $scope) {
                $records = is_array($scope[$recordKey] ?? null) ? $scope[$recordKey] : [];

                if ($signal !== 'metrics') {
                    $count += count($records);
                    $this->assertCount($count);

                    continue;
                }

                foreach ($records as $metric) {
                    foreach (['gauge', 'sum', 'histogram', 'exponentialHistogram', 'summary'] as $type) {
                        $points = $metric[$type]['dataPoints'] ?? null;
                        $count += is_array($points) ? count($points) : 0;
                        $this->assertCount($count);
                    }
                }
            }
        }
    }

    /** @param array<int, array<string, mixed>> $events */
    public function assertNormalizedSize(array $events): void
    {
        $this->assertCount(count($events));
        $bytes = 0;

        foreach ($events as $event) {
            $bytes += strlen(json_encode($event, JSON_THROW_ON_ERROR));
            abort_if($bytes > (int) config('monitor.beacon.telemetry.max_normalized_bytes'), 413, 'Expanded telemetry batch exceeds the storage-size limit.');
        }
    }

    private function assertCount(int $count): void
    {
        abort_if($count > (int) config('monitor.beacon.telemetry.max_events_per_batch'), 413, 'Telemetry batch exceeds the event limit.');
    }
}
