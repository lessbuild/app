<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Data\Telemetry\TraceRecord;
use App\Modules\Monitor\Models\Release;
use App\Modules\Monitor\Models\TelemetryEvent;

final class EventDetails
{
    public function __construct(private readonly TelemetryRedactor $redactor) {}

    /**
     * @return array{record: TraceRecord, attributesJson: string, payloadJson: string, release: Release|null}
     */
    public function data(TelemetryEvent $event): array
    {
        $event->setAttribute('source_signal', data_get($event->payload, 'signal'));
        $event->forceFill($this->redactor->redact($event->only(['name', 'route', 'service', 'attributes', 'payload'])));
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

        return [
            'release' => $event->release_id !== null ? Release::query()->where('application_id', $event->environment->application_id)->find($event->release_id) : null,
            'record' => new TraceRecord($event),
            'attributesJson' => json_encode($event->attributes ?? new \stdClass, $flags),
            'payloadJson' => json_encode($event->payload ?? new \stdClass, $flags),
        ];
    }
}
