<?php

declare(strict_types=1);

namespace App\Data\Telemetry;

final readonly class EventRow
{
    /**
     * Create a new EventRow instance.
     *
     * A telemetry event as lists show it (never its attributes or payload).
     *
     * @param  int  $id
     * @param  string  $occurredAt  ISO 8601, with microseconds.
     * @param  string  $type  request, query, job, exception, log, metric or other.
     * @param  string  $typeLabel  The type, in the person's language.
     * @param  string  $severity
     * @param  string  $tone  The badge tone: danger for errors, warning for warnings, neutral otherwise.
     * @param  string  $name  What happened, such as "GET /checkout" (redacted).
     * @param  string  $service
     * @param  string|null  $environment
     * @param  int|null  $statusCode
     * @param  string  $duration  Such as "120 ms", or a dash.
     * @param  string|null  $traceId
     * @param  int|null  $issueId
     * @param  bool  $hasError
     * @param  bool  $hasWarning
     */
    public function __construct(
        public int $id,
        public string $occurredAt,
        public string $type,
        public string $typeLabel,
        public string $severity,
        public string $tone,
        public string $name,
        public string $service,
        public ?string $environment,
        public ?int $statusCode,
        public string $duration,
        public ?string $traceId,
        public ?int $issueId,
        public bool $hasError,
        public bool $hasWarning,
    ) {}

    /**
     * Describe an event through its trace record (with its environment loaded).
     *
     * @param  TraceRecord  $record
     * @return self
     */
    public static function from(TraceRecord $record): self
    {
        $event = $record->event;

        return new self(
            id: $event->id,
            occurredAt: $event->occurred_at->format('Y-m-d\TH:i:s.uP'),
            type: $event->type,
            typeLabel: __(ucfirst($event->type)),
            severity: (string) $event->severity,
            tone: $record->tone(),
            name: $record->name(),
            service: $record->service(),
            environment: $event->environment->name,
            statusCode: $event->status_code,
            duration: $record->durationLabel(),
            traceId: $event->trace_id,
            issueId: $event->issue_id,
            hasError: $record->hasError,
            hasWarning: $record->hasWarning,
        );
    }
}
