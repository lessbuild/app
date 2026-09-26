<?php

namespace App\Modules\Monitor\Data\Telemetry;

use App\Modules\Monitor\Models\TelemetryEvent;

final readonly class TraceRecord
{
    public int $seconds;

    public int $nanoseconds;

    public ?float $durationMs;

    public bool $isSpan;

    public bool $hasError;

    public bool $hasWarning;

    public function __construct(public TelemetryEvent $event)
    {
        $start = OtlpTimestamp::isValid($event->timestamp_unix_nano)
            ? OtlpTimestamp::fromUnixNano($event->timestamp_unix_nano)
            : null;
        $end = OtlpTimestamp::isValid($event->end_timestamp_unix_nano)
            ? OtlpTimestamp::fromUnixNano($event->end_timestamp_unix_nano)
            : null;

        $this->seconds = $start?->seconds ?? $event->occurred_at->getTimestamp();
        $this->nanoseconds = $start?->nanoseconds ?? (int) $event->occurred_at->format('u') * 1_000;
        $duration = $start !== null && $end !== null
            ? $start->millisecondsUntil($end)
            : $event->duration_ms;
        $this->durationMs = $duration !== null && is_finite($duration) && $duration >= 0
            ? $duration
            : null;
        $this->isSpan = filled($event->span_id) && (
            $event->source_signal === 'traces'
            || (! in_array($event->source_signal, ['logs', 'metrics'], true)
                && in_array($event->type, ['request', 'query', 'job'], true))
        );
        $this->hasError = in_array($event->severity, ['error', 'critical'], true)
            || $event->type === 'exception'
            || $event->status_code >= 500;
        $this->hasWarning = $event->severity === 'warning'
            || ($event->status_code >= 400 && $event->status_code < 500);
    }

    public function millisecondsSince(self $origin): float
    {
        return round(($this->seconds - $origin->seconds) * 1_000
            + ($this->nanoseconds - $origin->nanoseconds) / 1_000_000, 6);
    }

    public function name(): string
    {
        return filled($this->event->name)
            ? $this->event->name
            : (filled($this->event->route) ? $this->event->route : 'Unnamed '.$this->event->type);
    }

    public function service(): string
    {
        return filled($this->event->service) ? $this->event->service : 'Unspecified service';
    }

    public function tone(): string
    {
        return $this->hasError ? 'red' : ($this->hasWarning ? 'amber' : ($this->isSpan ? 'violet' : 'slate'));
    }

    public function durationLabel(): string
    {
        return self::formatDuration($this->durationMs);
    }

    public static function formatDuration(?float $milliseconds): string
    {
        if ($milliseconds === null) {
            return 'Not reported';
        }

        return rtrim(rtrim(number_format($milliseconds, 6, '.', ','), '0'), '.').' ms';
    }
}
