<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\TelemetryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class TelemetryEventWriter
{
    public function __construct(
        private readonly TelemetryRedactor $redactor,
        private readonly RecordIssueOccurrence $issues,
    ) {}

    /** @param array<string, mixed> $event */
    public function store(Environment $environment, array $event, string $dedupeKey, CarbonImmutable $receivedAt, ?int $releaseId = null): TelemetryEvent
    {
        $attributes = $this->attributes($environment, $event, $receivedAt);
        $record = TelemetryEvent::query()->make(['dedupe_key' => $dedupeKey, ...$attributes]);

        if ($releaseId !== null) {
            $record->forceFill(['release_id' => $releaseId]);
        }

        $record->save();

        if ($event['type'] === 'exception') {
            $this->issues->record($environment, $event, $record, $receivedAt);
        }

        return $record;
    }

    /** @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function attributes(Environment $environment, array $event, CarbonImmutable $receivedAt): array
    {
        return [
            'environment_id' => $environment->id,
            'trace_id' => $event['trace_id'] ?? null,
            'span_id' => $event['span_id'] ?? null,
            'parent_span_id' => $event['parent_span_id'] ?? null,
            'type' => $event['type'],
            'severity' => $event['severity'] ?? 'info',
            'name' => $this->indexedText($event['name'] ?? $event['title'] ?? null, 255),
            'route' => $this->indexedText($event['route'] ?? $event['url'] ?? null, 255),
            'service' => $this->indexedText($event['service'] ?? null, 100),
            'status_code' => isset($event['status_code']) ? (int) $event['status_code'] : null,
            'duration_ms' => isset($event['duration_ms']) ? (float) $event['duration_ms'] : null,
            'attributes' => $event['attributes'] ?? null,
            'payload' => $event['payload'] ?? null,
            'occurred_at' => isset($event['timestamp']) ? CarbonImmutable::parse((string) $event['timestamp'])->utc() : $receivedAt,
            'timestamp_unix_nano' => $event['timestamp_unix_nano'] ?? null,
            'end_timestamp_unix_nano' => $event['end_timestamp_unix_nano'] ?? null,
        ];
    }

    private function indexedText(?string $value, int $limit): ?string
    {
        return $value === null ? null : Str::substr($value, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function prepare(array $event): array
    {
        $event = $this->redactor->redact($event);
        unset($event['id'], $event['content_identity']);
        $originalFields = [];

        foreach (['name' => 255, 'title' => 255, 'route' => 255, 'url' => 255, 'service' => 100] as $field => $limit) {
            if (isset($event[$field]) && Str::length($event[$field]) > $limit) {
                $originalFields[$field] = $event[$field];
            }
        }

        if ($originalFields !== []) {
            $payload = $event['payload'] ?? [];
            $metadata = ['indexed_fields' => $originalFields];

            if (array_key_exists('_beacon', $payload)) {
                $metadata['submitted_metadata'] = $payload['_beacon'];
            }

            $event['payload'] = [...$payload, '_beacon' => $metadata];
        }

        return $event;
    }
}
