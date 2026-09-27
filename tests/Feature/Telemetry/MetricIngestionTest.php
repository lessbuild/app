<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Models\Environment;
use App\Models\IngestToken;
use App\Models\MetricSample;
use App\Models\MetricSeries;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class MetricIngestionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_json_gauges_keep_signed_values_units_and_labels_with_replay_safe_samples(): void
    {
        $environment = $this->collector();
        $payload = ['batch_id' => 'json-gauge', 'events' => [[
            'type' => 'metric', 'name' => 'warehouse.temperature', 'service' => 'sensor',
            'attributes' => ['host.name' => 'freezer-1'], 'timestamp' => '2026-09-21T11:59:00Z',
            'payload' => ['value' => -12.5, 'unit' => 'Cel'],
        ]]];

        $this->postJson(route('api.ingest'), $payload)->assertOk()->assertJsonPath('data.accepted', 1);
        $this->postJson(route('api.ingest'), $payload)->assertOk()->assertJsonPath('data.accepted', 0);

        $series = MetricSeries::sole();
        $this->assertSame($environment->id, $series->environment_id);
        $this->assertSame('Cel', $series->unit);
        $this->assertSame(['host.name' => 'freezer-1'], $series->descriptor['attributes']);
        $this->assertSame(-12.5, $series->samples()->sole()->value);
        $this->assertDatabaseCount('metric_samples', 1);
    }

    public function test_otlp_resource_and_point_labels_are_separate_and_nanosecond_samples_do_not_merge(): void
    {
        $this->collector();
        $payload = $this->otlp();
        $payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0]['gauge']['dataPoints'][] = [
            'asDouble' => 0.9, 'timeUnixNano' => '1789991940000000001',
            'attributes' => [['key' => 'host.name', 'value' => ['stringValue' => 'point-override']]],
        ];

        $this->postJson(route('api.otlp', ['signal' => 'metrics']), $payload)->assertOk();

        $series = MetricSeries::sole();
        $this->assertSame('server-a', $series->descriptor['resource']['host.name']);
        $this->assertSame('point-override', $series->descriptor['attributes']['host.name']);
        $this->assertSame(['01789991940000000000', '01789991940000000001'], $series->samples()->orderBy('time_key')->pluck('time_key')->all());
    }

    #[DataProvider('identityChanges')]
    public function test_metric_identity_keeps_distinct_resources_labels_units_and_scopes_separate(string $path, mixed $value): void
    {
        $this->collector();
        $first = $this->otlp();
        $second = $first;
        data_set($second, $path, $value);

        $this->postJson(route('api.otlp', ['signal' => 'metrics']), $first)->assertOk();
        $this->postJson(route('api.otlp', ['signal' => 'metrics']), $second)->assertOk();

        $this->assertDatabaseCount('metric_series', 2);
        $this->assertDatabaseCount('metric_samples', 2);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function identityChanges(): array
    {
        $prefix = 'resourceMetrics.0.scopeMetrics.0.';

        return [
            'resource' => ['resourceMetrics.0.resource.attributes.0.value.stringValue', 'server-b'],
            'point label' => [$prefix.'metrics.0.gauge.dataPoints.0.attributes.0.value.stringValue', 'different'],
            'unit' => [$prefix.'metrics.0.unit', '%'],
            'name' => [$prefix.'metrics.0.name', 'system.cpu.other'],
            'scope name' => [$prefix.'scope.name', 'other-collector'],
            'scope version' => [$prefix.'scope.version', 'v2'],
            'scope attributes' => [$prefix.'scope.attributes', [['key' => 'mode', 'value' => ['stringValue' => 'alternate']]]],
            'resource schema' => ['resourceMetrics.0.schemaUrl', 'https://example.com/schema'],
            'scope schema' => [$prefix.'schemaUrl', 'https://example.com/scope'],
        ];
    }

    public function test_duplicate_source_time_does_not_overweight_a_series_and_conflicting_values_make_it_unknown(): void
    {
        $this->collector();
        $payload = $this->otlp();

        $this->withHeader('X-Beacon-Batch', 'first')->postJson(route('api.otlp', ['signal' => 'metrics']), $payload)->assertOk();
        $this->withHeader('X-Beacon-Batch', 'duplicate')->postJson(route('api.otlp', ['signal' => 'metrics']), $payload)->assertOk();
        $this->assertSame(0.5, MetricSample::sole()->value);
        data_set($payload, 'resourceMetrics.0.scopeMetrics.0.metrics.0.gauge.dataPoints.0.asDouble', 0.8);
        $this->withHeader('X-Beacon-Batch', 'conflict')->postJson(route('api.otlp', ['signal' => 'metrics']), $payload)->assertOk();

        $this->assertDatabaseCount('metric_samples', 1);
        $this->assertSame('conflict', MetricSample::sole()->state);
        $this->assertNull(MetricSample::sole()->value);
        $this->assertDatabaseCount('telemetry_events', 3);
    }

    #[DataProvider('unusableSamples')]
    public function test_unusable_readings_are_visible_gaps_not_zero(string $field, mixed $value, string $state): void
    {
        $this->collector();
        $payload = $this->otlp();
        data_set($payload, 'resourceMetrics.0.scopeMetrics.0.metrics.0.gauge.dataPoints.0.'.$field, $value);

        $this->postJson(route('api.otlp', ['signal' => 'metrics']), $payload)->assertOk();

        $this->assertSame($state, MetricSample::sole()->state);
        $this->assertNull(MetricSample::sole()->value);
        $this->assertDatabaseCount('telemetry_events', 1);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function unusableSamples(): array
    {
        return [
            'missing value flag' => ['flags', 1, 'no_value'],
            'large number' => ['asDouble', 1e20, 'out_of_range'],
            'unknown time' => ['timeUnixNano', '0', 'invalid_time'],
            'future time' => ['timeUnixNano', '1899991940000000000', 'invalid_time'],
            'old time' => ['timeUnixNano', '1000', 'invalid_time'],
        ];
    }

    public function test_histogram_counts_are_not_misrepresented_as_scalar_gauges(): void
    {
        $this->collector();
        $payload = $this->otlp();
        $metric = &$payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0];
        unset($metric['gauge']);
        $metric['histogram'] = ['aggregationTemporality' => 2, 'dataPoints' => [['timeUnixNano' => '1789991940000000000', 'count' => '5', 'sum' => 100]]];

        $this->postJson(route('api.otlp', ['signal' => 'metrics']), $payload)->assertOk();

        $this->assertSame('histogram', MetricSeries::sole()->kind);
        $this->assertSame('unsupported_type', MetricSample::sole()->state);
        $this->assertNull(MetricSample::sole()->value);
    }

    public function test_redacted_identity_values_remain_distinct_without_exposing_secrets_or_hashes(): void
    {
        $this->collector();
        $payload = $this->otlp();
        $payload['resourceMetrics'][0]['resource']['attributes'][] = ['key' => 'password', 'value' => ['stringValue' => 'secret-one']];
        $this->postJson(route('api.otlp', ['signal' => 'metrics']), $payload)->assertOk();
        $payload['resourceMetrics'][0]['resource']['attributes'][1]['value']['stringValue'] = 'secret-two';
        $this->postJson(route('api.otlp', ['signal' => 'metrics']), $payload)->assertOk();

        $this->assertDatabaseCount('metric_series', 2);
        foreach (MetricSeries::all() as $series) {
            $this->assertSame('[REDACTED]', $series->descriptor['resource']['password']);
            $this->assertArrayNotHasKey('identity_hash', $series->toArray());
            $this->assertStringNotContainsString('secret-', $series->toJson());
        }
    }

    public function test_custom_redaction_paths_apply_before_decoding_metric_labels(): void
    {
        $this->collector();
        config(['monitoring.telemetry.redacted_paths' => ['payload.data_point.attributes.*.value', 'payload.resource_attributes.host.name']]);

        $this->postJson(route('api.otlp', ['signal' => 'metrics']), $this->otlp())->assertOk();

        $series = MetricSeries::sole();
        $this->assertSame('[REDACTED]', $series->descriptor['resource']['host.name']);
        $this->assertSame('[REDACTED]', $series->descriptor['attributes']['host.name']);
    }

    public function test_projection_is_atomic_with_usage_and_event_persistence(): void
    {
        $this->collector();
        DB::unprepared("CREATE TRIGGER reject_metric_usage BEFORE INSERT ON telemetry_usage_entries BEGIN SELECT RAISE(ABORT, 'usage unavailable'); END");
        Exceptions::fake();

        $this->postJson(route('api.otlp', ['signal' => 'metrics']), $this->otlp())->assertServerError();

        $this->assertDatabaseEmpty('metric_series');
        $this->assertDatabaseEmpty('metric_samples');
        $this->assertDatabaseEmpty('telemetry_events');
    }

    public function test_json_cannot_impersonate_otlp_or_inject_server_owned_projection(): void
    {
        $environment = $this->collector();

        $this->postJson(route('api.ingest'), ['batch_id' => 'spoof', 'events' => [[
            'type' => 'metric', 'name' => 'safe', 'timestamp' => '2026-09-21T11:59:00Z',
            'metric_projection' => ['series' => ['environment_id' => 123]],
            'payload' => ['value' => 5, 'signal' => 'metrics', 'resource_attributes' => ['host.name' => 'spoofed']],
        ]]])->assertOk();

        $this->assertSame('json', MetricSeries::sole()->source);
        $this->assertSame($environment->id, MetricSeries::sole()->environment_id);
        $this->assertArrayNotHasKey('host.name', MetricSeries::sole()->descriptor['resource']);
    }

    private function collector(): Environment
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $environment = Environment::factory()->create();
        IngestToken::factory()->for($environment)->withSecret('metrics-test-key')->create();
        $this->withToken('metrics-test-key');

        return $environment;
    }

    /**
     * @return array<string, mixed>
     */
    private function otlp(): array
    {
        return ['resourceMetrics' => [[
            'resource' => ['attributes' => [['key' => 'host.name', 'value' => ['stringValue' => 'server-a']]]],
            'scopeMetrics' => [['scope' => ['name' => 'hostmetrics', 'version' => 'v1'], 'metrics' => [[
                'name' => 'system.cpu.utilization', 'unit' => '1', 'gauge' => ['dataPoints' => [[
                    'asDouble' => 0.5, 'timeUnixNano' => '1789991940000000000',
                    'attributes' => [['key' => 'host.name', 'value' => ['stringValue' => 'point-override']]],
                ]]],
            ]]]],
        ]]];
    }
}
