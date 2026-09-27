<?php

declare(strict_types=1);

namespace App\Queries\Telemetry;

use App\Data\Telemetry\TraceRecord;
use App\Models\Release;
use App\Models\TelemetryEvent;
use App\Services\Monitoring\TelemetryRedactor;
use stdClass;

final class EventDetailsQuery
{
    /**
     * Prepares one telemetry event for its detail page.
     *
     * @param  TelemetryRedactor  $redactor  Removes secrets from the event before it's shown.
     */
    public function __construct(private readonly TelemetryRedactor $redactor) {}

    /**
     * The event redacted, as a trace record, with its attributes and payload as pretty JSON, and the release it belongs
     * to.
     *
     * @return array{record: TraceRecord, attributesJson: string, payloadJson: string, release: Release|null}
     */
    public function handle(TelemetryEvent $event): array
    {
        $event->setAttribute('source_signal', data_get($event->payload, 'signal'));
        $event->forceFill($this->redactor->redact($event->only(['name', 'route', 'service', 'attributes', 'payload'])));
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

        return [
            'release' => $event->release_id !== null ? Release::query()->where('project_id', $event->environment->project_id)->find($event->release_id) : null,
            'record' => new TraceRecord($event),
            'attributesJson' => json_encode($event->attributes ?? new stdClass, $flags),
            'payloadJson' => json_encode($event->payload ?? new stdClass, $flags),
        ];
    }
}
