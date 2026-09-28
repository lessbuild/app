<?php

declare(strict_types=1);

namespace App\Data\Telemetry;

use App\Models\TelemetryEvent;

final readonly class TraceRecord
{
    /**
     * Whole seconds of the event's start, from the precise OTLP timestamp when there is one, else from `occurred_at`.
     *
     * @var int
     */
    public int $seconds;

    /**
     * The nanoseconds past `$seconds`, so spans in the same millisecond still order correctly.
     *
     * @var int
     */
    public int $nanoseconds;

    /**
     * The span's length, from its start and end timestamps when both are valid, else the stored duration. Null when
     * unknown or nonsensical (negative or infinite).
     *
     * @var float|null
     */
    public ?float $durationMs;

    /**
     * Whether the event is a span in the trace: it has a span ID and is either an OTLP trace or a request, query or job
     * event. Logs and metrics with a span ID are shown as events attached to the trace instead.
     *
     * @var bool
     */
    public bool $isSpan;

    /**
     * Whether the event failed: error or critical severity, an exception, or a 5xx status.
     *
     * @var bool
     */
    public bool $hasError;

    /**
     * Whether the event is a warning or a 4xx response.
     *
     * @var bool
     */
    public bool $hasWarning;

    /**
     * Works out the timing and state of one event in a trace waterfall.
     *
     * @param  TelemetryEvent  $event  The stored telemetry event.
     */
    public function __construct(public TelemetryEvent $event)
    {
        $start = OtlpTimestamp::isValid($event->timestamp_unix_nano)
            ? OtlpTimestamp::fromUnixNano($event->timestamp_unix_nano)
            : null;
        $end = OtlpTimestamp::isValid($event->end_timestamp_unix_nano)
            ? OtlpTimestamp::fromUnixNano($event->end_timestamp_unix_nano)
            : null;

        $this->seconds = $start !== null ? $start->seconds : $event->occurred_at->getTimestamp();
        $this->nanoseconds = $start !== null ? $start->nanoseconds : (int) $event->occurred_at->format('u') * 1_000;
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

    /**
     * How far after `$origin` (normally the trace's first event) this event started, for placing it on the waterfall.
     *
     * @param  TraceRecord  $origin
     * @return float
     */
    public function millisecondsSince(self $origin): float
    {
        return round(($this->seconds - $origin->seconds) * 1_000
            + ($this->nanoseconds - $origin->nanoseconds) / 1_000_000, 6);
    }

    /**
     * What to call the event on the waterfall: its name, else its route, else "Unnamed" and its type.
     *
     * @return string
     */
    public function name(): string
    {
        return filled($this->event->name)
            ? $this->event->name
            : (filled($this->event->route) ? $this->event->route : 'Unnamed '.$this->event->type);
    }

    /**
     * The service that emitted the event, or "Unspecified service".
     *
     * @return string
     */
    public function service(): string
    {
        return filled($this->event->service) ? $this->event->service : 'Unspecified service';
    }

    /**
     * The waterfall colour: red for errors, amber for warnings, accent for spans and neutral for other events.
     *
     * @return string
     */
    public function tone(): string
    {
        return $this->hasError ? 'danger' : ($this->hasWarning ? 'warning' : ($this->isSpan ? 'accent' : 'neutral'));
    }

    /**
     * The duration formatted for display.
     *
     * @return string
     */
    public function durationLabel(): string
    {
        return self::formatDuration($this->durationMs);
    }

    /**
     * Formats milliseconds with up to six decimals and no trailing zeros, or "Not reported" when unknown.
     *
     * @param  float|null  $milliseconds
     * @return string
     */
    public static function formatDuration(?float $milliseconds): string
    {
        if ($milliseconds === null) {
            return __('Not reported');
        }

        return rtrim(rtrim(number_format($milliseconds, 6, '.', ','), '0'), '.').' ms';
    }
}
