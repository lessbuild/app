<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Data\Telemetry\IngestSource;
use App\Modules\Monitor\Data\Telemetry\OtlpTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class MetricProjection
{
    public function __construct(private readonly TelemetryRedactor $redactor, private readonly OtlpPayloadMapper $mapper) {}

    /**
     * Server-owned metadata lives outside the submitted event and retry fingerprint.
     *
     * @param  array<string, mixed>  $event
     * @return array{series: array<string, mixed>, sample: array<string, mixed>}|null
     */
    public function prepare(array $event, IngestSource $source, CarbonImmutable $receivedAt): ?array
    {
        if (($event['type'] ?? null) !== 'metric' || ! in_array($source, [IngestSource::Json, IngestSource::OtlpMetrics], true)) {
            return null;
        }
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $isOtlp = $source === IngestSource::OtlpMetrics;
        $metric = $isOtlp && is_array($payload['metric'] ?? null) ? $payload['metric'] : [];
        $point = $isOtlp && is_array($payload['data_point'] ?? null) ? $payload['data_point'] : [];
        $kinds = array_values(array_intersect(['gauge', 'sum', 'histogram', 'exponentialHistogram', 'summary'], array_keys($metric)));
        $kind = $isOtlp ? (count($kinds) === 1 ? $kinds[0] : 'unsupported') : 'gauge';
        $temporality = match ($metric[$kind]['aggregationTemporality'] ?? null) {
            1, '1', 'AGGREGATION_TEMPORALITY_DELTA' => 'delta',
            2, '2', 'AGGREGATION_TEMPORALITY_CUMULATIVE' => 'cumulative',
            default => null,
        };
        $descriptor = $this->descriptor($event, $isOtlp);
        $identity = hash('sha256', serialize($this->canonical([$source->value, $kind, $temporality, (bool) ($metric[$kind]['isMonotonic'] ?? false), $descriptor])));
        $sanitized = $this->redactor->redact($event);
        $descriptor = $this->redactor->redact($this->descriptor($sanitized, $isOtlp));
        $payload = is_array($sanitized['payload'] ?? null) ? $sanitized['payload'] : [];
        $point = is_array($payload['data_point'] ?? null) ? $payload['data_point'] : [];
        $value = $isOtlp ? ($point['asDouble'] ?? $point['asInt'] ?? null) : ($payload['value'] ?? null);
        $state = in_array($kind, ['gauge', 'sum'], true) ? 'valid' : 'unsupported_type';
        if ($state === 'valid' && (((int) ($point['flags'] ?? 0)) & 1) === 1) {
            $state = 'no_value';
        }
        if ($state === 'valid' && (! is_numeric($value) || is_bool($value) || ! is_finite((float) $value) || abs((float) $value) > 9007199254740991)) {
            $state = 'out_of_range';
        }
        $time = $isOtlp ? $this->timestamp($point['timeUnixNano'] ?? null) : (isset($event['timestamp']) ? CarbonImmutable::parse($event['timestamp'])->utc() : null);
        if ($time === null || $time->year < 2000 || $time->greaterThan($receivedAt->addMinutes(5))) {
            $state = 'invalid_time';
            $time = $receivedAt;
        }
        $timeKey = $state === 'invalid_time' || ! $isOtlp ? self::timeKey($time) : str_pad(ltrim((string) $point['timeUnixNano'], '0'), 20, '0', STR_PAD_LEFT);
        $start = $this->timestamp($point['startTimeUnixNano'] ?? null);
        $startKey = $start !== null ? str_pad(ltrim((string) $point['startTimeUnixNano'], '0'), 20, '0', STR_PAD_LEFT) : null;

        return [
            'series' => [
                'identity_hash' => $identity, 'source' => $source->value,
                'name' => Str::substr((string) $descriptor['name'], 0, 255), 'unit' => Str::substr((string) $descriptor['unit'], 0, 255),
                'resource_label' => $this->resourceLabel($descriptor),
                'kind' => $kind, 'temporality' => $temporality, 'monotonic' => (bool) ($metric[$kind]['isMonotonic'] ?? false),
                'descriptor' => $descriptor,
            ],
            'sample' => [
                'time_key' => $timeKey, 'start_time_key' => $startKey,
                'value_hash' => hash('sha256', serialize([$state, $state === 'valid' ? (float) $value : $value, $startKey])),
                'value_text' => is_scalar($value) ? Str::substr((string) $value, 0, 64) : null,
                'value' => $state === 'valid' ? (float) $value : null, 'state' => $state,
                'occurred_at' => $time->format('Y-m-d H:i:s.u'), 'received_at' => $receivedAt->format('Y-m-d H:i:s.u'),
            ],
        ];
    }

    public static function timeKey(CarbonImmutable $time): string
    {
        return str_pad($time->format('Uu').'000', 20, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function descriptor(array $event, bool $isOtlp): array
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $pointAttributes = $payload['data_point']['attributes'] ?? [];
        $scopeAttributes = $payload['scope']['attributes'] ?? [];
        $unit = $isOtlp ? ($payload['metric']['unit'] ?? '') : ($payload['unit'] ?? '');

        return [
            'name' => $isOtlp ? ($payload['metric']['name'] ?? 'metric') : ($event['name'] ?? 'metric'),
            'unit' => is_string($unit) ? $unit : '',
            'resource' => $isOtlp ? ($payload['resource_attributes'] ?? []) : ['service.name' => $event['service'] ?? null],
            'attributes' => $isOtlp ? (is_array($pointAttributes) ? $this->mapper->attributes($pointAttributes) : $pointAttributes) : ($event['attributes'] ?? []),
            'scope' => $isOtlp ? [
                'name' => $payload['scope']['name'] ?? null, 'version' => $payload['scope']['version'] ?? null,
                'attributes' => is_array($scopeAttributes) ? $this->mapper->attributes($scopeAttributes) : $scopeAttributes,
            ] : [],
            'resource_schema_url' => $isOtlp ? ($payload['resource_schema_url'] ?? null) : null,
            'scope_schema_url' => $isOtlp ? ($payload['scope_schema_url'] ?? null) : null,
        ];
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! OtlpTimestamp::isValid($value) || ltrim((string) $value, '0') === '') {
            return null;
        }

        return CarbonImmutable::parse(OtlpTimestamp::fromUnixNano($value)->iso8601());
    }

    /** @param array<string, mixed> $descriptor */
    private function resourceLabel(array $descriptor): string
    {
        foreach (['resource', 'attributes'] as $group) {
            foreach (['host.name', 'host.id', 'server.address', 'container.name', 'container.id', 'k8s.pod.name', 'service.instance.id', 'service.name'] as $key) {
                $value = $descriptor[$group][$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return Str::substr($value, 0, 255);
                }
            }
        }

        return 'Unspecified resource';
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonical(...), $value);
    }
}
