<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Models\IngestToken;
use App\Models\TelemetryEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class OtlpPrecisionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    #[DataProvider('preciseSpans')]
    public function test_span_timestamps_and_duration_keep_nanosecond_precision(string $start, string $end, string $microseconds, float $duration): void
    {
        IngestToken::factory()->withSecret('precise-span-key')->create();

        $this->withToken('precise-span-key')->postJson(route('api.otlp', 'traces'), $this->trace([
            'startTimeUnixNano' => $start, 'endTimeUnixNano' => $end,
        ]))->assertOk();

        $event = TelemetryEvent::sole();
        $this->assertSame($start, $event->timestamp_unix_nano);
        $this->assertSame($end, $event->end_timestamp_unix_nano);
        $this->assertSame($microseconds, $event->occurred_at->format('u'));
        $this->assertSame($duration, $event->duration_ms);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function preciseSpans(): array
    {
        return [
            'one nanosecond' => ['1700000000123456789', '1700000000123456790', '123456', 0.000001],
            'cross-second one nanosecond' => ['1700000000999999999', '1700000001000000000', '999999', 0.000001],
            'large duration' => ['1700000000000000000', '1700086400000000000', '000000', 86_400_000.0],
            'maximum uint64' => ['18446744073709551614', '18446744073709551615', '709551', 0.000001],
            'epoch' => ['0', '1', '000000', 0.000001],
        ];
    }

    public function test_trace_identifiers_are_normalized_for_cross_service_correlation(): void
    {
        IngestToken::factory()->withSecret('case-span-key')->create();

        $this->withToken('case-span-key')->postJson(route('api.otlp', 'traces'), $this->trace([
            'traceId' => str_repeat('A', 32), 'spanId' => str_repeat('B', 16), 'parentSpanId' => str_repeat('C', 16),
        ]))->assertOk();

        $event = TelemetryEvent::sole();
        $this->assertSame(str_repeat('a', 32), $event->trace_id);
        $this->assertSame(str_repeat('b', 16), $event->span_id);
        $this->assertSame(str_repeat('c', 16), $event->parent_span_id);
    }

    #[DataProvider('invalidSpans')]
    public function test_invalid_trace_identifiers_timestamps_and_enums_return_400(string $field, mixed $value): void
    {
        $token = IngestToken::factory()->withSecret('invalid-span-key')->create();

        $this->withToken('invalid-span-key')->postJson(route('api.otlp', 'traces'), $this->trace([$field => $value]))
            ->assertBadRequest()->assertJsonPath('code', 3);

        $this->assertDatabaseCount('telemetry_events', 0);
        $this->assertSame(0, $token->environment->telemetry_event_count);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function invalidSpans(): array
    {
        return [
            'short trace ID' => ['traceId', 'abcd'],
            'non-hex trace ID' => ['traceId', str_repeat('g', 32)],
            'zero trace ID' => ['traceId', str_repeat('0', 32)],
            'zero span ID' => ['spanId', str_repeat('0', 16)],
            'short parent ID' => ['parentSpanId', 'abcd'],
            'negative timestamp' => ['startTimeUnixNano', '-1'],
            'overflow timestamp' => ['startTimeUnixNano', '18446744073709551616'],
            'fractional timestamp' => ['startTimeUnixNano', '1700000000.1'],
            'floating-point timestamp' => ['startTimeUnixNano', 1.5],
            'boolean timestamp' => ['startTimeUnixNano', true],
            'timestamp container' => ['startTimeUnixNano', ['invalid']],
            'end before start' => ['endTimeUnixNano', '1699999999999999999'],
            'enum name' => ['kind', 'SPAN_KIND_SERVER'],
            'string enum number' => ['kind', '2'],
            'status enum name' => ['status', ['code' => 'STATUS_CODE_OK']],
        ];
    }

    public function test_logs_differing_by_one_nanosecond_are_not_merged_and_retries_are_deduplicated(): void
    {
        $token = IngestToken::factory()->withSecret('precise-log-key')->create();
        $payload = $this->logs([
            ['body' => ['stringValue' => 'Same message'], 'timeUnixNano' => '1700000000000000001'],
            ['body' => ['stringValue' => 'Same message'], 'timeUnixNano' => '1700000000000000002'],
        ]);

        $this->withToken('precise-log-key')->postJson(route('api.otlp', 'logs'), $payload)
            ->assertOk()->assertHeader('X-Beacon-Accepted', '2');
        $this->postJson(route('api.otlp', 'logs'), $payload)
            ->assertOk()->assertHeader('X-Beacon-Accepted', '0')->assertHeader('X-Beacon-Duplicates', '2');

        $this->assertSame(['1700000000000000001', '1700000000000000002'], TelemetryEvent::orderBy('id')->pluck('timestamp_unix_nano')->all());
        $this->assertSame(2, $token->environment->telemetry_event_count);
    }

    public function test_identical_log_occurrences_in_one_export_are_preserved_on_replay(): void
    {
        IngestToken::factory()->withSecret('repeated-log-key')->create();
        $record = ['body' => ['stringValue' => 'Repeated message'], 'timeUnixNano' => '1700000000000000000'];
        $payload = $this->logs([$record, $record]);

        $this->withToken('repeated-log-key')->postJson(route('api.otlp', 'logs'), $payload)
            ->assertOk()->assertHeader('X-Beacon-Accepted', '2');
        $this->postJson(route('api.otlp', 'logs'), $payload)
            ->assertOk()->assertHeader('X-Beacon-Duplicates', '2');

        $this->assertDatabaseCount('telemetry_events', 2);
    }

    public function test_log_identity_includes_resource_scope_severity_and_attributes(): void
    {
        IngestToken::factory()->withSecret('log-context-key')->create();
        $record = ['body' => ['stringValue' => 'Message'], 'timeUnixNano' => '1700000000000000000'];
        $payload = $this->logs([
            $record + ['severityNumber' => 9],
            $record + ['severityNumber' => 17],
            $record + ['attributes' => [['key' => 'worker', 'value' => ['stringValue' => 'one']]]],
            $record + ['attributes' => [['key' => 'worker', 'value' => ['stringValue' => 'two']]]],
        ]);
        $payload['resourceLogs'][0]['scopeLogs'][] = ['scope' => ['name' => 'another-library'], 'logRecords' => [$record]];
        $payload['resourceLogs'][] = [
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'another-service']]]],
            'scopeLogs' => [['logRecords' => [$record]]],
        ];

        $this->withToken('log-context-key')->postJson(route('api.otlp', 'logs'), $payload)
            ->assertOk()->assertHeader('X-Beacon-Accepted', '6');

        $this->assertDatabaseCount('telemetry_events', 6);
        $this->assertSame(['another-library'], TelemetryEvent::orderBy('id')->get()->pluck('payload.scope.name')->filter()->values()->all());
    }

    public function test_reordered_record_keys_and_attribute_lists_do_not_create_extra_events(): void
    {
        IngestToken::factory()->withSecret('ordered-log-key')->create();
        $first = [
            'body' => ['stringValue' => 'Message'], 'timeUnixNano' => '1700000000000000000',
            'attributes' => [
                ['key' => 'first', 'value' => ['stringValue' => 'one']],
                ['key' => 'second', 'value' => ['stringValue' => 'two']],
            ],
        ];
        $second = [
            'attributes' => array_reverse($first['attributes']),
            'timeUnixNano' => 1700000000000000000, 'body' => ['stringValue' => 'Message'],
        ];

        $this->withToken('ordered-log-key')->postJson(route('api.otlp', 'logs'), $this->logs([$first]))->assertOk();
        $this->postJson(route('api.otlp', 'logs'), $this->logs([$second]))
            ->assertOk()->assertHeader('X-Beacon-Duplicates', '1');

        $this->assertDatabaseCount('telemetry_events', 1);
    }

    public function test_unset_log_timestamp_uses_observed_time_before_receiver_time(): void
    {
        IngestToken::factory()->withSecret('observed-time-key')->create();

        $this->withToken('observed-time-key')->postJson(route('api.otlp', 'logs'), $this->logs([[
            'body' => ['stringValue' => 'Observed'], 'timeUnixNano' => '0', 'observedTimeUnixNano' => '1700000000123456789',
        ]]))->assertOk();

        $event = TelemetryEvent::sole();
        $this->assertSame('1700000000123456789', $event->timestamp_unix_nano);
        $this->assertSame('2023-11-14 22:13:20.123456', $event->occurred_at->format('Y-m-d H:i:s.u'));
    }

    #[DataProvider('severityNumbers')]
    public function test_severity_numbers_determine_the_normalized_log_level(int $number, string $expected): void
    {
        IngestToken::factory()->withSecret('severity-number-key')->create();

        $this->withToken('severity-number-key')->postJson(route('api.otlp', 'logs'), $this->logs([[
            'body' => ['stringValue' => 'Severity'], 'severityNumber' => $number, 'severityText' => 'INFO',
        ]]))->assertOk();

        $this->assertSame($expected, TelemetryEvent::sole()->severity);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function severityNumbers(): array
    {
        return [
            'unset' => [0, 'info'], 'trace' => [1, 'debug'], 'trace upper' => [4, 'debug'],
            'debug' => [5, 'debug'], 'debug upper' => [8, 'debug'], 'info' => [9, 'info'],
            'info upper' => [12, 'info'], 'warn' => [13, 'warning'], 'warn upper' => [16, 'warning'],
            'error' => [17, 'error'], 'error upper' => [20, 'error'], 'fatal' => [21, 'critical'], 'fatal upper' => [24, 'critical'],
        ];
    }

    public function test_histograms_with_equal_counts_but_different_buckets_are_not_merged(): void
    {
        IngestToken::factory()->withSecret('histogram-identity-key')->create();
        $payload = ['resourceMetrics' => [['scopeMetrics' => [['metrics' => [[
            'name' => 'latency', 'histogram' => ['aggregationTemporality' => 2, 'dataPoints' => [
                ['timeUnixNano' => '1700000000000000000', 'count' => '3', 'explicitBounds' => [10], 'bucketCounts' => ['1', '2']],
                ['timeUnixNano' => '1700000000000000000', 'count' => '3', 'explicitBounds' => [10], 'bucketCounts' => ['2', '1']],
            ]],
        ]]]]]]];

        $this->withToken('histogram-identity-key')->postJson(route('api.otlp', 'metrics'), $payload)
            ->assertOk()->assertHeader('X-Beacon-Accepted', '2');
        $this->postJson(route('api.otlp', 'metrics'), $payload)->assertOk()->assertHeader('X-Beacon-Duplicates', '2');

        $this->assertDatabaseCount('telemetry_events', 2);
    }

    public function test_generic_timestamps_are_stored_in_utc_with_microseconds(): void
    {
        IngestToken::factory()->withSecret('generic-precision-key')->create();

        $this->withToken('generic-precision-key')->postJson(route('api.ingest'), [
            'batch_id' => 'timezone', 'events' => [['type' => 'log', 'timestamp' => '2026-09-20T12:00:00.123456+03:00']],
        ])->assertOk();

        $this->assertSame('2026-09-20 09:00:00.123456', TelemetryEvent::sole()->occurred_at->format('Y-m-d H:i:s.u'));
    }

    #[DataProvider('invalidLogFields')]
    public function test_invalid_log_timing_and_severity_return_400(string $field, mixed $value): void
    {
        IngestToken::factory()->withSecret('invalid-log-field-key')->create();

        $this->withToken('invalid-log-field-key')->postJson(route('api.otlp', 'logs'), $this->logs([[
            'body' => ['stringValue' => 'Invalid field'], $field => $value,
        ]]))->assertBadRequest()->assertJsonPath('details.0.fieldViolations.0.field', 'resourceLogs.0.scopeLogs.0.logRecords.0.'.$field);

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function invalidLogFields(): array
    {
        return [
            'empty source time' => ['timeUnixNano', ''],
            'negative observed time' => ['observedTimeUnixNano', '-1'],
            'string severity' => ['severityNumber', '17'],
            'negative severity' => ['severityNumber', -1],
            'above severity range' => ['severityNumber', 25],
            'container severity' => ['severityNumber', ['invalid']],
        ];
    }

    public function test_metrics_reject_conflicting_data_types_and_invalid_timestamps(): void
    {
        IngestToken::factory()->withSecret('invalid-metric-field-key')->create();

        $this->withToken('invalid-metric-field-key')->postJson(route('api.otlp', 'metrics'), [
            'resourceMetrics' => [['scopeMetrics' => [['metrics' => [[
                'name' => 'invalid.metric', 'gauge' => ['dataPoints' => [['timeUnixNano' => '-1', 'asInt' => '1']]],
                'sum' => ['aggregationTemporality' => 'AGGREGATION_TEMPORALITY_DELTA', 'dataPoints' => []],
            ]]]]]],
        ])->assertBadRequest()->assertJsonCount(3, 'details.0.fieldViolations');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function trace(array $overrides = []): array
    {
        return ['resourceSpans' => [['scopeSpans' => [['spans' => [$overrides + [
            'traceId' => str_repeat('a', 32), 'spanId' => str_repeat('b', 16), 'name' => 'GET /orders',
            'startTimeUnixNano' => '1700000000000000000', 'endTimeUnixNano' => '1700000000001000000', 'kind' => 2,
        ]]]]]]];
    }

    /** @param array<int, array<string, mixed>> $records
     * @return array<string, mixed>
     */
    private function logs(array $records): array
    {
        return ['resourceLogs' => [['scopeLogs' => [['logRecords' => $records]]]]];
    }
}
