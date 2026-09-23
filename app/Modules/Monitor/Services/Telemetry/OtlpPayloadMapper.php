<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Contracts\TelemetryPayloadMapper;
use App\Modules\Monitor\Data\Telemetry\OtlpTimestamp;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class OtlpPayloadMapper implements TelemetryPayloadMapper
{
    private const EVENT_TYPES = ['request', 'query', 'job', 'exception', 'log', 'metric'];

    /**
     * @param  array<string, mixed>  $payload
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
                    $bodyText = is_scalar($body) ? (string) $body : json_encode($body);
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
                        'name' => Str::limit($this->stringValue($this->attribute($attributes, 'log.logger', 'logger.name')) ?? $bodyText ?? 'Log record', 255, ''),
                        'title' => $type === 'exception' ? Str::limit((string) ($bodyText ?? 'Unhandled exception'), 255, '') : null,
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
     * @param  array<string, mixed>  $dataPoint
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

    private function attribute(array $attributes, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $attributes)) {
                return $attributes[$key];
            }
        }

        return null;
    }

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

    private function serviceName(array $attributes): ?string
    {
        return $this->stringValue($this->attribute($attributes, 'service.name', 'service'));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function statusCode(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $statusCode = (int) $value;

        return $statusCode >= 100 && $statusCode <= 599 ? $statusCode : null;
    }

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

    private function knownTimestamp(mixed $value): ?OtlpTimestamp
    {
        $timestamp = OtlpTimestamp::fromUnixNano($value);

        return $timestamp?->unixNano === '0' ? null : $timestamp;
    }

    private function identifier(?string $value): ?string
    {
        return $value === null || $value === '' ? null : strtolower($value);
    }

    /**
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
     * @param  array<int, mixed>  $identity
     * @param  array<string, int>  $occurrences
     */
    private function recordIdentity(string $signal, array $identity, array &$occurrences): string
    {
        $hash = hash('sha256', serialize($this->canonical($identity)));
        $occurrence = $occurrences[$hash] ?? 0;
        $occurrences[$hash] = $occurrence + 1;

        return $signal.'-v2:'.$hash.':'.$occurrence;
    }

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
