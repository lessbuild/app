<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Data\Telemetry\IngestContext;
use App\Modules\Monitor\Data\Telemetry\IngestSource;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Models\TelemetryEventIdentity;
use Illuminate\Validation\ValidationException;

final class LegacyEventReplay
{
    public function __construct(
        private readonly IngestIdentity $identity,
        private readonly TelemetryRedactor $redactor,
    ) {}

    /** @param list<array<string, mixed>> $events
     * @return array<string, int|null>
     */
    public function candidates(Environment $environment, string $batchId, array $events): array
    {
        $keys = [];

        foreach ($events as $index => $event) {
            $keys[] = hash('sha256', $environment->id.'|'.$batchId.'|'.($event['id'] ?? $index));
        }

        $identities = TelemetryEventIdentity::query()->whereIn('dedupe_key', $keys)->where('version', 1)
            ->pluck('telemetry_event_id', 'dedupe_key')->all();
        $records = TelemetryEvent::query()->whereIn('dedupe_key', $keys)->pluck('id', 'dedupe_key')->all();

        return $identities + $records;
    }

    /** @param array<string, mixed> $original
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int|null>  $candidates
     */
    public function find(
        Environment $environment,
        string $batchId,
        IngestContext $context,
        array $original,
        int $index,
        array $attributes,
        array $candidates,
    ): ?TelemetryEvent {
        $eventId = (string) ($original['id'] ?? $index);
        $key = hash('sha256', $environment->id.'|'.$batchId.'|'.$eventId);

        if (! array_key_exists($key, $candidates)) {
            return null;
        }

        $record = $candidates[$key] === null ? null : TelemetryEvent::query()->find($candidates[$key]);

        if ($record === null || ! isset($original['id']) || ctype_digit($eventId)
            || str_contains($eventId, '|') || str_contains($batchId, '|')) {
            $this->reject($index);
        }

        $legacySignal = $record->payload['signal'] ?? null;
        $source = $context->source;

        if ($source !== IngestSource::Json && $context->explicitBatch && $batchId === 'otlp:'.$source->signal()) {
            $this->reject($index);
        }

        if (($source === IngestSource::Json && in_array($legacySignal, ['traces', 'logs', 'metrics'], true))
            || ($source !== IngestSource::Json && $legacySignal !== $source->signal())) {
            $this->reject($index);
        }

        if (isset($original['timestamp']) && ! $record->occurred_at->equalTo($attributes['occurred_at'])) {
            $this->reject($index);
        }

        unset($attributes['occurred_at']);
        $stored = [];

        foreach (array_keys($attributes) as $field) {
            $stored[$field] = $record->getAttribute($field);
        }

        if ($this->identity->canonical($this->redactor->redact($stored)) !== $this->identity->canonical($attributes)) {
            $this->reject($index);
        }

        return $record;
    }

    private function reject(int $index): never
    {
        throw ValidationException::withMessages([
            'events.'.$index.'.id' => 'This legacy identity cannot be verified safely. Inspect the existing event before reusing its batch or event ID.',
        ]);
    }
}
