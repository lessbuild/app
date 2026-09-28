<?php

declare(strict_types=1);

namespace App\Services\Telemetry;

use App\Contracts\Telemetry\TelemetryPayloadMapper;
use App\Data\Telemetry\OtlpTimestamp;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class OtlpPayloadMapper implements TelemetryPayloadMapper
{
    private const EVENT_TYPES = ['request', 'query', 'job', 'exception', 'log', 'metric'];

    /**
     * Converts an OTLP/JSON export into events for the signal.
     *
     * @param  array<string, mixed>  $payload
     * @param  string  $signal
     * @return array<int, array<string, mixed>>
     */
    public function map(array $payload, string $signal): array
    {
        return match ($signal) {
            'traces' => $this->mapTraces($payload),
            'logs' => $this->mapLogs($payload),
            'metrics' => $this->mapMetrics($payload),
            default => throw new InvalidArgumentException("Unsupported OTLP signal [{$signal}]."),
        };
    }

    /**
     * One event per span.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function mapTraces(array $payload): array
    {
        $events = [];

        foreach ($payload['resourceSpans'] ?? [] as $resourceSpan) {
            if (! is_array($resourceSpan)) {
                continue;
            }

            $resourceAttributes = $this->attributes($resourceSpan['resource']['attributes'] ?? []);

            foreach ($resourceSpan['scopeSpans'] ?? [] as $scopeSpan) {
                if (! is_array($scopeSpan)) {
                    continue;
                }

                foreach ($scopeSpan['spans'] ?? [] as $span) {
                    if (! is_array($span)) {
                        continue;
                    }

                    $events[] = $this->spanEvent($span, $resourceAttributes);
                }
            }
        }

        return $events;
    }

    /**
     * One event per log record, with its type, severity and name read from OTLP conventions and a stable ID made from
     * its content.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function mapLogs(array $payload): array
    {
        $events = [];
        $occurrences = [];

        foreach ($payload['resourceLogs'] ?? [] as $resourceLog) {
            if (! is_array($resourceLog)) {
                continue;
            }

            $resourceAttributes = $this->attributes($resourceLog['resource']['attributes'] ?? []);

            foreach ($resourceLog['scopeLogs'] ?? [] as $scopeLog) {
                if (! is_array($scopeLog)) {
                    continue;
                }

                foreach ($scopeLog['logRecords'] ?? [] as $record) {
                    if (! is_array($record)) {
                        continue;
                    }

                    $recordAttributes = $this->attributes($record['attributes'] ?? []);
                    $attributes = array_merge($resourceAttributes, $recordAttributes);
                    $body = $this->value($record['body'] ?? null);
                    $bodyText = is_scalar($body) ? (string) $body : (string) json_encode($body);
                    $explicitType = $this->stringValue($this->attribute($attributes, 'beacon.event.type', 'event.type'));
                    $type = $this->eventType($explicitType, $attributes, 'log');
                    $time = $this->knownTimestamp($record['timeUnixNano'] ?? null)
                        ?? $this->knownTimestamp($record['observedTimeUnixNano'] ?? null);
                    $traceId = $this->identifier($record['traceId'] ?? null);
                    $spanId = $this->identifier($record['spanId'] ?? null);
                    $severity = $this->logSeverity($record['severityText'] ?? null, $record['severityNumber'] ?? null, $type);
                    $stableId = $this->recordIdentity('log', [
                        $resourceAttributes, $resourceLog['schemaUrl'] ?? null,
                        $scopeLog['scope'] ?? null, $scopeLog['schemaUrl'] ?? null,
                        $this->identityRecord($record, $recordAttributes),
                    ], $occurrences);

                    $events[] = [
                        'id' => $stableId,
                        'content_identity' => $stableId,
                        'type' => $type,
                        'severity' => $severity,
                        'name' => Str::limit($this->stringValue($this->attribute($attributes, 'log.logger', 'logger.name')) ?? ($bodyText !== '' ? $bodyText : 'Log record'), 255, ''),
                        'title' => $type === 'exception' ? Str::limit($bodyText !== '' ? $bodyText : 'Unhandled exception', 255, '') : null,
                        'route' => $this->stringValue($this->attribute($attributes, 'http.route', 'url.path')),
                        'service' => $this->serviceName($attributes),
                        'trace_id' => $traceId,
                        'span_id' => $spanId,
                        'timestamp' => $time?->iso8601(),
                        'timestamp_unix_nano' => $time?->unixNano,
                        'details' => $this->stringValue($this->attribute($attributes, 'exception.stacktrace')),
                        'attributes' => $attributes,
                        'payload' => [
                            'signal' => 'logs',
                            'record' => $record,
                            'resource_attributes' => $resourceAttributes,
                            'scope' => $scopeLog['scope'] ?? null,
                            'resource_schema_url' => $resourceLog['schemaUrl'] ?? null,
                            'scope_schema_url' => $scopeLog['schemaUrl'] ?? null,
                        ],
                    ];
                }
            }
        }

        return $events;
    }

    /**
     * One event per metric data point, keeping the metric's metadata without the other points.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function mapMetrics(array $payload): array
    {
        $events = [];
        $occurrences = [];

        foreach ($payload['resourceMetrics'] ?? [] as $resourceMetric) {
            if (! is_array($resourceMetric)) {
                continue;
            }

            $resourceAttributes = $this->attributes($resourceMetric['resource']['attributes'] ?? []);

            foreach ($resourceMetric['scopeMetrics'] ?? [] as $scopeMetric) {
                if (! is_array($scopeMetric)) {
                    continue;
                }

                foreach ($scopeMetric['metrics'] ?? [] as $metric) {
                    if (! is_array($metric)) {
                        continue;
                    }

                    $metricMetadata = $metric;

                    foreach (['gauge', 'sum', 'histogram', 'exponentialHistogram', 'summary'] as $metricType) {
                        unset($metricMetadata[$metricType]['dataPoints']);
                    }

                    foreach ($this->metricDataPoints($metric) as $dataPoint) {
                        $pointAttributes = $this->attributes($dataPoint['attributes'] ?? []);
                        $attributes = array_merge($resourceAttributes, $pointAttributes);
                        $time = $this->knownTimestamp($dataPoint['timeUnixNano'] ?? null)
                            ?? $this->knownTimestamp($dataPoint['startTimeUnixNano'] ?? null);
                        $value = $this->metricValue($dataPoint);
                        $metricName = (string) ($metric['name'] ?? 'metric');
                        $stableId = $this->recordIdentity('metric', [
                            $resourceAttributes, $resourceMetric['schemaUrl'] ?? null,
                            $scopeMetric['scope'] ?? null, $scopeMetric['schemaUrl'] ?? null,
                            $metricMetadata, $this->identityRecord($dataPoint, $pointAttributes),
                        ], $occurrences);

                        $events[] = [
                            'id' => $stableId,
                            'content_identity' => $stableId,
                            'type' => 'metric',
                            'severity' => 'info',
                            'name' => Str::limit($metricName, 255, ''),
                            'service' => $this->serviceName($attributes),
                            'timestamp' => $time?->iso8601(),
                            'timestamp_unix_nano' => $time?->unixNano,
                            'attributes' => $attributes,
                            'payload' => [
                                'signal' => 'metrics',
                                'metric' => $metricMetadata,
                                'data_point' => $dataPoint,
                                'value' => $value,
                                'resource_attributes' => $resourceAttributes,
                                'scope' => $scopeMetric['scope'] ?? null,
                                'resource_schema_url' => $resourceMetric['schemaUrl'] ?? null,
                                'scope_schema_url' => $scopeMetric['schemaUrl'] ?? null,
                            ],
                        ];
                    }
                }
            }
        }

        return $events;
    }

    /**
     * A span as an event: its type from OTLP conventions (request, query, job or exception), severity from its status
     * and HTTP code, duration from its timestamps, and its trace, span and parent IDs.
     *
     * @param  array<string, mixed>  $span
     * @param  array<string, mixed>  $resourceAttributes
     * @return array<string, mixed>
     */
    private function spanEvent(array $span, array $resourceAttributes): array
    {
        $spanAttributes = $this->attributes($span['attributes'] ?? []);
        $attributes = array_merge($resourceAttributes, $spanAttributes);
        $traceId = $this->identifier($span['traceId'] ?? null);
        $spanId = $this->identifier($span['spanId'] ?? null);
        $start = OtlpTimestamp::fromUnixNano($span['startTimeUnixNano'] ?? null);
        $end = OtlpTimestamp::fromUnixNano($span['endTimeUnixNano'] ?? null);
        $statusCode = $this->statusCode($this->attribute($attributes, 'http.response.status_code', 'http.status_code'));
        $type = $this->eventType(
            $this->stringValue($this->attribute($attributes, 'beacon.event.type', 'event.type')),
            $attributes,
            'request',
        );
        $severity = $this->spanSeverity($span['status']['code'] ?? null, $statusCode, $type);
        $stableId = $traceId && $spanId ? $traceId.':'.$spanId : hash('sha256', serialize($span));
        $title = $this->stringValue($this->attribute($attributes, 'exception.message'));

        return [
            'id' => $stableId,
            'content_identity' => hash('sha256', serialize($this->canonical([
                $resourceAttributes, $this->identityRecord($span, $spanAttributes),
            ]))),
            'type' => $type,
            'severity' => $severity,
            'name' => Str::limit((string) ($span['name'] ?? 'Span'), 255, ''),
            'title' => $type === 'exception' ? Str::limit($title ?? (string) ($span['name'] ?? 'Unhandled exception'), 255, '') : null,
            'route' => $this->stringValue($this->attribute($attributes, 'http.route', 'url.path', 'http.target', 'rpc.method')),
            'service' => $this->serviceName($attributes),
            'status_code' => $statusCode,
            'duration_ms' => $start !== null && $end !== null ? $start->millisecondsUntil($end) : null,
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $this->identifier($span['parentSpanId'] ?? null),
            'timestamp' => $start?->iso8601(),
            'timestamp_unix_nano' => $start?->unixNano,
            'end_timestamp_unix_nano' => $end?->unixNano,
            'details' => $this->stringValue($this->attribute($attributes, 'exception.stacktrace')),
            'attributes' => $attributes,
            'payload' => [
                'signal' => 'traces',
                'span' => $span,
                'resource_attributes' => $resourceAttributes,
            ],
        ];
    }

    /**
     * Every data point of a metric, whatever its type.
     *
     * @param  array<string, mixed>  $metric
     * @return array<int, array<string, mixed>>
     */
    private function metricDataPoints(array $metric): array
    {
        $points = [];

        foreach (['gauge', 'sum', 'histogram', 'exponentialHistogram', 'summary'] as $type) {
            $dataPoints = $metric[$type]['dataPoints'] ?? [];

            if (is_array($dataPoints)) {
                foreach ($dataPoints as $dataPoint) {
                    if (is_array($dataPoint)) {
                        $points[] = $dataPoint;
                    }
                }
            }
        }

        return $points;
    }

    /**
     * A data point's value: the gauge or sum value, else a histogram's count or sum.
     *
     * @param  array<string, mixed>  $dataPoint
     * @return mixed
     */
    private function metricValue(array $dataPoint): mixed
    {
        foreach (['asDouble', 'asInt', 'count', 'sum'] as $key) {
            if (array_key_exists($key, $dataPoint)) {
                return $dataPoint[$key];
            }
        }

        return null;
    }

    /**
     * An OTLP attribute list as a key-value array.
     *
     * @param  array<int, mixed>  $rawAttributes
     * @return array<string, mixed>
     */
    public function attributes(array $rawAttributes): array
    {
        $attributes = [];

        foreach ($rawAttributes as $attribute) {
            if (! is_array($attribute) || ! isset($attribute['key'])) {
                continue;
            }

            $attributes[(string) $attribute['key']] = $this->value($attribute['value'] ?? null);
        }

        return $attributes;
    }

    /**
     * An OTLP `AnyValue` as a plain PHP value, recursively for arrays and key-value lists.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function value(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach (['stringValue', 'boolValue', 'intValue', 'doubleValue', 'bytesValue'] as $key) {
            if (array_key_exists($key, $value)) {
                return $value[$key];
            }
        }

        if (isset($value['arrayValue']['values']) && is_array($value['arrayValue']['values'])) {
            return array_map(fn (mixed $item): mixed => $this->value($item), $value['arrayValue']['values']);
        }

        if (isset($value['kvlistValue']['values']) && is_array($value['kvlistValue']['values'])) {
            return $this->attributes($value['kvlistValue']['values']);
        }

        return $value;
    }

    /**
     * The first of several attribute keys that's present, since conventions changed names over versions.
     *
     * @param  array<string, mixed>  $attributes
     * @param  string  ...$keys
     * @return mixed
     */
    private function attribute(array $attributes, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $attributes)) {
                return $attributes[$key];
            }
        }

        return null;
    }

    /**
     * The event's type: an explicit `beacon.event.type` or `event.type` when it's known, else exception, query or job
     * from the attributes present, else the signal's default.
     *
     * @param  string|null  $explicitType
     * @param  array<string, mixed>  $attributes
     * @param  string  $fallback
     * @return string
     */
    private function eventType(?string $explicitType, array $attributes, string $fallback): string
    {
        if ($explicitType && in_array($explicitType, self::EVENT_TYPES, true)) {
            return $explicitType;
        }

        if ($this->attribute($attributes, 'exception.type', 'exception.message') !== null) {
            return 'exception';
        }

        if ($this->attribute($attributes, 'db.system', 'db.statement') !== null) {
            return 'query';
        }

        if ($this->attribute($attributes, 'messaging.system', 'job.name', 'faas.trigger') !== null) {
            return 'job';
        }

        return $fallback;
    }

    /**
     * The service name from `service.name`, or `service`.
     *
     * @param  array<string, mixed>  $attributes
     * @return string|null
     */
    private function serviceName(array $attributes): ?string
    {
        return $this->stringValue($this->attribute($attributes, 'service.name', 'service'));
    }

    /**
     * A non-empty scalar as a string, or null.
     *
     * @param  mixed  $value
     * @return string|null
     */
    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * An HTTP status between 100 and 599, or null.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private function statusCode(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $statusCode = (int) $value;

        return $statusCode >= 100 && $statusCode <= 599 ? $statusCode : null;
    }

    /**
     * A span's severity: error for exceptions, failed status or 5xx, warning for 4xx, info otherwise.
     *
     * @param  mixed  $status
     * @param  int|null  $statusCode
     * @param  string  $type
     * @return string
     */
    private function spanSeverity(mixed $status, ?int $statusCode, string $type): string
    {
        if ($type === 'exception' || $status === 2 || $statusCode >= 500) {
            return 'error';
        }

        if ($statusCode >= 400) {
            return 'warning';
        }

        return 'info';
    }

    /**
     * A log record's severity from its number (OTLP's 1–24 scale) or, without one, from its text.
     *
     * @param  mixed  $severity
     * @param  int|null  $number
     * @param  string  $type
     * @return string
     */
    private function logSeverity(mixed $severity, ?int $number, string $type): string
    {
        if ($number !== null && $number > 0) {
            return match (true) {
                $number >= 21 => 'critical',
                $number >= 17 => 'error',
                $number >= 13 => 'warning',
                $number >= 9 => 'info',
                default => 'debug',
            };
        }

        $severity = strtolower((string) $severity);

        if (str_contains($severity, 'fatal') || str_contains($severity, 'critical') || str_contains($severity, 'emerg')) {
            return 'critical';
        }

        if ($type === 'exception' || str_contains($severity, 'error')) {
            return 'error';
        }

        if (str_contains($severity, 'warn')) {
            return 'warning';
        }

        if (str_contains($severity, 'debug') || str_contains($severity, 'trace')) {
            return 'debug';
        }

        return 'info';
    }

    /**
     * A timestamp, treating zero as unknown.
     *
     * @param  mixed  $value
     * @return OtlpTimestamp|null
     */
    private function knownTimestamp(mixed $value): ?OtlpTimestamp
    {
        $timestamp = OtlpTimestamp::fromUnixNano($value);

        return $timestamp?->unixNano === '0' ? null : $timestamp;
    }

    /**
     * A trace or span ID lowercased, or null when empty.
     *
     * @param  string|null  $value
     * @return string|null
     */
    private function identifier(?string $value): ?string
    {
        return $value === null || $value === '' ? null : strtolower($value);
    }

    /**
     * A record prepared for fingerprinting: decoded attributes, canonical timestamps and lowercased IDs, so equivalent
     * encodings match.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function identityRecord(array $record, array $attributes): array
    {
        $record['attributes'] = $attributes;

        foreach (['timeUnixNano', 'observedTimeUnixNano', 'startTimeUnixNano', 'endTimeUnixNano'] as $field) {
            if (isset($record[$field])) {
                $record[$field] = OtlpTimestamp::fromUnixNano($record[$field])?->unixNano;
            }
        }

        foreach (['traceId', 'spanId', 'parentSpanId'] as $field) {
            if (isset($record[$field])) {
                $record[$field] = $this->identifier($record[$field]);
            }
        }

        return $record;
    }

    /**
     * A stable ID from a record's content, numbered when identical records appear more than once in the same export.
     *
     * @param  string  $signal
     * @param  array<int, mixed>  $identity
     * @param  array<string, int>  $occurrences
     * @return string
     */
    private function recordIdentity(string $signal, array $identity, array &$occurrences): string
    {
        $hash = hash('sha256', serialize($this->canonical($identity)));
        $occurrence = $occurrences[$hash] ?? 0;
        $occurrences[$hash] = $occurrence + 1;

        return $signal.'-v2:'.$hash.':'.$occurrence;
    }

    /**
     * Sorts object keys recursively, so equal data serialises the same.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonical(...), $value);
    }
}
