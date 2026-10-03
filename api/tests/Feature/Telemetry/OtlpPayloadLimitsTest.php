<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Models\IngestToken;
use App\Models\TelemetryEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class OtlpPayloadLimitsTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    #[DataProvider('recordSignals')]
    public function test_record_limits_are_counted_across_resources_and_scopes(string $signal, string $resourceKey): void
    {
        config(['monitoring.telemetry.max_events_per_batch' => 1]);
        $token = IngestToken::factory()->withSecret('otlp-count-key')->create();
        $payload = $this->payload($signal);
        $payload[$resourceKey][] = $payload[$resourceKey][0];

        $this->withToken('otlp-count-key')->postJson(route('api.otlp', $signal), $payload)
            ->assertStatus(413)->assertJsonPath('message', 'Telemetry batch exceeds the event limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
        $this->assertSame(0, $token->environment->telemetry_event_count);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function recordSignals(): array
    {
        return ['traces' => ['traces', 'resourceSpans'], 'logs' => ['logs', 'resourceLogs']];
    }

    #[DataProvider('metricFamilies')]
    public function test_metric_limits_count_every_data_point_not_just_metric_names(string $family): void
    {
        config(['monitoring.telemetry.max_events_per_batch' => 1]);
        $token = IngestToken::factory()->withSecret('metric-count-key')->create();
        $payload = ['resourceMetrics' => [['scopeMetrics' => [['metrics' => [[
            'name' => 'sample', $family => ['dataPoints' => [['asInt' => '1'], ['asInt' => '2']]],
        ]]]]]]];

        $this->withToken('metric-count-key')->postJson(route('api.otlp', 'metrics'), $payload)
            ->assertStatus(413)->assertJsonPath('message', 'Telemetry batch exceeds the event limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
        $this->assertSame(0, $token->environment->telemetry_event_count);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function metricFamilies(): array
    {
        return [
            'gauge' => ['gauge'], 'sum' => ['sum'], 'histogram' => ['histogram'],
            'exponential histogram' => ['exponentialHistogram'], 'summary' => ['summary'],
        ];
    }

    public function test_metric_limits_are_aggregated_across_families_resources_and_scopes(): void
    {
        config(['monitoring.telemetry.max_events_per_batch' => 1]);
        IngestToken::factory()->withSecret('metric-total-key')->create();
        $payload = $this->payload('metrics');
        $payload['resourceMetrics'][] = ['scopeMetrics' => [['metrics' => [[
            'name' => 'requests', 'sum' => ['dataPoints' => [['asInt' => '3']]],
        ]]]]];

        $this->withToken('metric-total-key')->postJson(route('api.otlp', 'metrics'), $payload)
            ->assertStatus(413)->assertJsonPath('message', 'Telemetry batch exceeds the event limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    #[DataProvider('invalidStructures')]
    public function test_malformed_otlp_structures_return_400_without_persisting_events(string $signal, string $path, mixed $value, string $message): void
    {
        $token = IngestToken::factory()->withSecret('invalid-structure-key')->create();
        $payload = $this->payload($signal);
        Arr::set($payload, $path, $value);

        $response = $this->withToken('invalid-structure-key')->postJson(route('api.otlp', $signal), $payload);
        $this->assertOtlpValidationError($response, $path, $message);

        $this->assertDatabaseCount('telemetry_events', 0);
        $this->assertDatabaseCount('issues', 0);
        $this->assertSame(0, $token->environment->telemetry_event_count);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function invalidStructures(): array
    {
        $span = 'resourceSpans.0.scopeSpans.0.spans.0';
        $metric = 'resourceMetrics.0.scopeMetrics.0.metrics.0';
        $arrayMessage = 'field must be an array.';

        return [
            'root scalar' => ['traces', 'resourceSpans', 'invalid', $arrayMessage],
            'root map' => ['traces', 'resourceSpans', ['invalid' => []], 'field must be a list.'],
            'resource scalar' => ['traces', 'resourceSpans.0', false, $arrayMessage],
            'resource metadata scalar' => ['traces', 'resourceSpans.0.resource', false, $arrayMessage],
            'scope list scalar' => ['traces', 'resourceSpans.0.scopeSpans', 'invalid', $arrayMessage],
            'scope scalar' => ['traces', 'resourceSpans.0.scopeSpans.0', true, $arrayMessage],
            'scope metadata scalar' => ['traces', 'resourceSpans.0.scopeSpans.0.scope', true, $arrayMessage],
            'record list scalar' => ['traces', 'resourceSpans.0.scopeSpans.0.spans', 'invalid', $arrayMessage],
            'record scalar' => ['traces', $span, 123, $arrayMessage],
            'attributes scalar' => ['traces', $span.'.attributes', true, $arrayMessage],
            'attribute scalar' => ['traces', $span.'.attributes.0', false, $arrayMessage],
            'attribute key array' => ['traces', $span.'.attributes.0.key', ['invalid'], 'field must be a string.'],
            'attribute value scalar' => ['traces', $span.'.attributes.0.value', 'invalid', $arrayMessage],
            'span name array' => ['traces', $span.'.name', ['invalid'], 'field must be a string.'],
            'trace ID array' => ['traces', $span.'.traceId', ['invalid'], 'field must be a string.'],
            'log body scalar' => ['logs', 'resourceLogs.0.scopeLogs.0.logRecords.0.body', 'invalid', $arrayMessage],
            'log severity array' => ['logs', 'resourceLogs.0.scopeLogs.0.logRecords.0.severityText', ['invalid'], 'field must be a string.'],
            'metric family scalar' => ['metrics', $metric.'.gauge', true, $arrayMessage],
            'data point list scalar' => ['metrics', $metric.'.gauge.dataPoints', true, $arrayMessage],
            'data point scalar' => ['metrics', $metric.'.gauge.dataPoints.0', 'invalid', $arrayMessage],
            'data point attributes scalar' => ['metrics', $metric.'.gauge.dataPoints.0.attributes', true, $arrayMessage],
        ];
    }

    #[DataProvider('attributePaths')]
    public function test_attribute_lists_are_bounded_before_they_are_expanded(string $signal, string $path): void
    {
        config(['monitoring.telemetry.max_attributes_per_record' => 1]);
        IngestToken::factory()->withSecret('attributes-limit-key')->create();
        $payload = $this->payload($signal);
        Arr::set($payload, $path, [
            ['key' => 'first', 'value' => ['stringValue' => 'value']],
            ['key' => 'second', 'value' => ['stringValue' => 'value']],
        ]);

        $response = $this->withToken('attributes-limit-key')->postJson(route('api.otlp', $signal), $payload);
        $this->assertOtlpValidationError($response, $path, 'must not have more than 1 items.');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function attributePaths(): array
    {
        return [
            'resource' => ['traces', 'resourceSpans.0.resource.attributes'],
            'instrumentation scope' => ['traces', 'resourceSpans.0.scopeSpans.0.scope.attributes'],
            'record' => ['logs', 'resourceLogs.0.scopeLogs.0.logRecords.0.attributes'],
            'metric point' => ['metrics', 'resourceMetrics.0.scopeMetrics.0.metrics.0.gauge.dataPoints.0.attributes'],
        ];
    }

    /**
     * @param  array<mixed>  $value
     */
    #[DataProvider('invalidValues')]
    public function test_invalid_nested_values_cannot_cause_mapping_errors(array $value): void
    {
        IngestToken::factory()->withSecret('invalid-value-key')->create();
        $payload = $this->payload('logs');
        $path = 'resourceLogs.0.scopeLogs.0.logRecords.0.body';
        Arr::set($payload, $path, $value);

        $response = $this->withToken('invalid-value-key')->postJson(route('api.otlp', 'logs'), $payload);
        $this->assertOtlpValidationError($response, $path, 'must contain a valid OTLP value.');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function invalidValues(): array
    {
        return [
            'string container' => [['stringValue' => ['invalid']]],
            'bytes container' => [['bytesValue' => ['invalid']]],
            'boolean string' => [['boolValue' => 'false']],
            'integer container' => [['intValue' => ['invalid']]],
            'double container' => [['doubleValue' => ['invalid']]],
            'multiple types' => [['stringValue' => 'invalid', 'intValue' => '1']],
            'array wrapper' => [['arrayValue' => true]],
            'array values' => [['arrayValue' => ['values' => true]]],
            'array scalar' => [['arrayValue' => ['values' => ['invalid']]]],
            'nested key' => [['kvlistValue' => ['values' => [['key' => ['invalid']]]]]],
            'nested scalar' => [['kvlistValue' => ['values' => ['invalid']]]],
        ];
    }

    public function test_a_bad_record_rejects_the_whole_batch_instead_of_partially_storing_it(): void
    {
        $token = IngestToken::factory()->withSecret('atomic-otlp-key')->create();
        $payload = $this->payload('logs');
        $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][] = ['body' => ['stringValue' => ['invalid']]];

        $this->withToken('atomic-otlp-key')->postJson(route('api.otlp', 'logs'), $payload)
            ->assertBadRequest()->assertJsonPath('details.0.fieldViolations.0.field', 'resourceLogs.0.scopeLogs.0.logRecords.1.body');

        $this->assertDatabaseCount('telemetry_events', 0);
        $this->assertSame(0, $token->environment->telemetry_event_count);
    }

    public function test_metric_points_keep_their_values_without_copying_the_entire_series_into_every_event(): void
    {
        config(['monitoring.telemetry.max_events_per_batch' => 2]);
        IngestToken::factory()->withSecret('metric-values-key')->create();
        $points = [
            ['timeUnixNano' => '1700000000000000000', 'asInt' => '7', 'flags' => 1],
            ['timeUnixNano' => '1700000001000000000', 'asInt' => '9', 'flags' => 0],
        ];
        $payload = ['resourceMetrics' => [['scopeMetrics' => [['metrics' => [[
            'name' => 'queue.depth', 'unit' => '{job}', 'description' => 'Pending work',
            'gauge' => ['dataPoints' => $points],
        ]]]]]]];

        $this->withToken('metric-values-key')->postJson(route('api.otlp', 'metrics'), $payload)
            ->assertOk()->assertHeader('X-Beacon-Accepted', '2');

        $events = TelemetryEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $first = $events->firstOrFail();
        $second = $events->skip(1)->firstOrFail();
        $this->assertSame('7', ($first->payload ?? [])['value']);
        $this->assertSame('9', ($second->payload ?? [])['value']);
        $this->assertSame($points[0], ($first->payload ?? [])['data_point']);
        $this->assertSame($points[1], ($second->payload ?? [])['data_point']);
        $this->assertSame([
            'name' => 'queue.depth', 'unit' => '{job}', 'description' => 'Pending work', 'gauge' => [],
        ], ($first->payload ?? [])['metric']);
        $this->assertSame('2023-11-14 22:13:20', $first->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('2023-11-14 22:13:21', $second->occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_histogram_points_keep_bucket_data_and_temporality(): void
    {
        IngestToken::factory()->withSecret('histogram-key')->create();
        $point = [
            'timeUnixNano' => '1700000000000000000', 'count' => '3', 'sum' => 25.5,
            'explicitBounds' => [10, 20], 'bucketCounts' => ['2', '1', '0'], 'min' => 1, 'max' => 15,
        ];
        $payload = ['resourceMetrics' => [['scopeMetrics' => [['metrics' => [[
            'name' => 'request.duration', 'unit' => 'ms',
            'histogram' => ['aggregationTemporality' => 2, 'dataPoints' => [$point]],
        ]]]]]]];

        $this->withToken('histogram-key')->postJson(route('api.otlp', 'metrics'), $payload)->assertSuccessful();

        $event = TelemetryEvent::sole();
        $this->assertSame($point, ($event->payload ?? [])['data_point']);
        $this->assertSame(['aggregationTemporality' => 2], ($event->payload ?? [])['metric']['histogram']);
    }

    public function test_shared_resource_expansion_is_bounded_before_storage(): void
    {
        config(['monitoring.telemetry.max_normalized_bytes' => 1_000]);
        $token = IngestToken::factory()->withSecret('fanout-key')->create();
        $payload = $this->payload('traces');
        $payload['resourceSpans'][0]['resource']['attributes'] = [
            ['key' => 'description', 'value' => ['stringValue' => str_repeat('x', 400)]],
        ];
        $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][] = [
            'name' => 'Second span', 'traceId' => str_repeat('a', 32), 'spanId' => str_repeat('c', 16),
            'startTimeUnixNano' => '1700000000000000000', 'endTimeUnixNano' => '1700000000001000000',
        ];

        $this->withToken('fanout-key')->postJson(route('api.otlp', 'traces'), $payload)
            ->assertStatus(413)->assertJsonPath('message', 'Expanded telemetry batch exceeds the storage-size limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
        $this->assertSame(0, $token->environment->telemetry_event_count);
    }

    public function test_otlp_does_not_read_telemetry_from_query_parameters(): void
    {
        IngestToken::factory()->withSecret('query-otlp-key')->create();

        $this->call(
            'POST',
            route('api.otlp', 'traces').'?'.http_build_query($this->payload('traces')),
            server: $this->transformHeadersToServerVars([
                'Authorization' => 'Bearer query-otlp-key', 'Content-Type' => 'application/json',
            ]),
            content: '{}',
        )->assertOk()->assertContent('{}')->assertHeader('X-Beacon-Accepted', '0');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    /**
     * @param  array<mixed>  $value
     */
    #[DataProvider('validValues')]
    public function test_valid_typed_values_retain_their_content(array $value): void
    {
        IngestToken::factory()->withSecret('typed-value-key')->create();
        $payload = $this->payload('logs');
        Arr::set($payload, 'resourceLogs.0.scopeLogs.0.logRecords.0.body', $value);

        $this->withToken('typed-value-key')->postJson(route('api.otlp', 'logs'), $payload)->assertSuccessful();

        $this->assertSame($value, (TelemetryEvent::sole()->payload ?? [])['record']['body']);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function validValues(): array
    {
        return [
            'string' => [['stringValue' => ' text ']],
            'empty string' => [['stringValue' => '']],
            'bytes' => [['bytesValue' => 'aGVsbG8=']],
            'boolean' => [['boolValue' => false]],
            'integer number' => [['intValue' => 123]],
            'integer string' => [['intValue' => '9223372036854775807']],
            'double' => [['doubleValue' => 1.5]],
            'special float string' => [['doubleValue' => 'NaN']],
            'empty typed value' => [[]],
            'null typed value' => [['stringValue' => null]],
            'list' => [['arrayValue' => ['values' => [['stringValue' => 'one'], ['intValue' => '2']]]]],
            'map' => [['kvlistValue' => ['values' => [['key' => 'useful', 'value' => ['stringValue' => 'value']]]]]],
        ];
    }

    /** @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response */
    private function assertOtlpValidationError(TestResponse $response, string $field, string $message): void
    {
        $response->assertBadRequest()->assertJsonPath('code', 3);
        /** @var list<array{field: string, description: string}> $violations */
        $violations = (array) $response->json('details.0.fieldViolations');
        $violation = collect($violations)->firstWhere('field', $field);
        $this->assertNotNull($violation, 'Missing OTLP violation for '.$field);
        $this->assertStringContainsString($message, $violation['description']);
    }

    /** @return array<string, mixed> */
    private function payload(string $signal): array
    {
        return match ($signal) {
            'traces' => ['resourceSpans' => [['scopeSpans' => [['spans' => [[
                'name' => 'GET /orders', 'traceId' => str_repeat('a', 32), 'spanId' => str_repeat('b', 16),
                'startTimeUnixNano' => '1700000000000000000', 'endTimeUnixNano' => '1700000000001000000',
            ]]]]]]],
            'logs' => ['resourceLogs' => [['scopeLogs' => [['logRecords' => [[
                'body' => ['stringValue' => 'Example log'], 'timeUnixNano' => '1700000000000000000',
            ]]]]]]],
            'metrics' => ['resourceMetrics' => [['scopeMetrics' => [['metrics' => [[
                'name' => 'queue.depth', 'gauge' => ['dataPoints' => [['asInt' => '7']]],
            ]]]]]]],
            default => throw new LogicException($signal),
        };
    }
}
