<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Models\Environment;
use App\Models\IngestToken;
use App\Models\Release;
use App\Models\TelemetryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ReleaseIngestionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_json_events_link_by_app_service_namespace_and_case_sensitive_version_with_source_time_bounds(): void
    {
        $environment = $this->collector();
        $payload = ['batch_id' => 'releases', 'events' => [
            ['type' => 'request', 'service' => 'api', 'attributes' => ['service.version' => ' v1 ', 'service.namespace' => 'shop'], 'timestamp' => '2026-09-21T12:00:00.500000Z'],
            ['type' => 'exception', 'service' => 'api', 'attributes' => ['service.version' => 'v1', 'service.namespace' => 'shop'], 'timestamp' => '2026-09-21T11:00:00Z'],
            ['type' => 'log', 'service' => 'api', 'attributes' => ['service.version' => 'V1', 'service.namespace' => 'shop']],
            ['type' => 'log', 'service' => 'worker', 'attributes' => ['service.version' => 'v1', 'service.namespace' => 'shop']],
            ['type' => 'log', 'service' => 'api', 'attributes' => ['service.version' => 'v1', 'service.namespace' => 'billing']],
        ]];

        $this->postJson(route('api.ingest'), $payload)->assertOk()->assertJsonPath('data.accepted', 5);
        $this->postJson(route('api.ingest'), $payload)->assertOk()->assertJsonPath('data.accepted', 0);

        $this->assertDatabaseCount('releases', 4);
        $this->assertDatabaseCount('telemetry_events', 5);
        $release = Release::query()->where('version', 'v1')->where('service', 'api')->where('service_namespace', 'shop')->sole();
        $this->assertSame($environment->project_id, $release->project_id);
        $this->assertSame(2, $release->telemetryEvents()->count());
        $this->assertSame('2026-09-21T11:00:00.000000Z', $release->first_seen_at?->toISOString());
        $this->assertSame('2026-09-21T12:00:00.500000Z', $release->last_seen_at?->toISOString());
        $this->assertNotNull($release->telemetryEvents()->where('type', 'exception')->sole()->issue_id);
        $this->assertDatabaseEmpty('deployments');
    }

    public function test_same_service_version_shares_a_release_across_environments_but_never_applications(): void
    {
        $first = $this->collector();
        $events = [['type' => 'log', 'attributes' => ['service.version' => '1.0', 'service.name' => 'worker']]];
        $this->postJson(route('api.ingest'), ['batch_id' => 'first', 'events' => $events])->assertOk();
        $this->collector(Environment::factory()->for($first->project)->create(['slug' => 'staging']));
        $this->postJson(route('api.ingest'), ['batch_id' => 'second', 'events' => $events])->assertOk();
        $this->collector();
        $this->postJson(route('api.ingest'), ['batch_id' => 'third', 'events' => $events])->assertOk();

        $this->assertDatabaseCount('releases', 2);
        $this->assertSame(2, Release::query()->where('project_id', $first->project_id)->sole()->telemetryEvents()->count());
    }

    /**
     * @param  array<mixed>  $attributes
     */
    #[DataProvider('unusableLabels')]
    public function test_telemetry_without_a_valid_release_identity_is_kept_unlinked(array $attributes, ?string $service): void
    {
        $this->collector();

        $this->postJson(route('api.ingest'), ['batch_id' => 'unlinked', 'events' => [[
            'type' => 'log', 'service' => $service, 'attributes' => $attributes,
            'payload' => ['signal' => 'traces', 'resource_attributes' => ['service.version' => 'spoofed']],
            'release_id' => 123,
        ]]])->assertOk()->assertJsonPath('data.accepted', 1);

        $this->assertNull(TelemetryEvent::sole()->release_id);
        $this->assertDatabaseEmpty('releases');
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function unusableLabels(): array
    {
        return [
            'missing' => [[], null],
            'numeric' => [['service.version' => 12], null],
            'empty' => [['service.version' => '  '], null],
            'long version' => [['service.version' => str_repeat('a', 129)], null],
            'redacted' => [['service.version' => '[REDACTED]'], null],
            'control' => [['service.version' => "v1\n"], null],
            'invalid namespace' => [['service.version' => 'v1', 'service.namespace' => ['nested']], null],
            'long service fallback' => [['service.version' => 'v1', 'service.name' => str_repeat('s', 101)], null],
            'bad top-level service' => [['service.version' => 'v1'], "api\t"],
        ];
    }

    public function test_version_zero_is_valid_and_missing_time_uses_receipt_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $this->collector();

        $this->postJson(route('api.ingest'), ['batch_id' => 'zero', 'events' => [[
            'type' => 'log', 'attributes' => ['service.version' => '0'],
        ]]])->assertOk();

        $release = Release::sole();
        $this->assertSame('0', $release->version);
        $this->assertNull($release->service);
        $this->assertSame('2026-09-21T12:00:00.000000Z', $release->first_seen_at?->toISOString());
    }

    /**
     * @param  array<mixed>  $record
     */
    #[DataProvider('otlpSignals')]
    public function test_otlp_uses_resource_version_not_record_overrides(string $signal, string $resourceKey, string $scopeKey, string $recordKey, array $record): void
    {
        $this->collector();
        $override = [['key' => 'service.version', 'value' => ['stringValue' => 'wrong-record-version']]];
        if ($signal === 'metrics') {
            $record['gauge']['dataPoints'][0]['attributes'] = $override;
        } else {
            $record['attributes'] = $override;
        }
        $payload = [$resourceKey => [[
            'resource' => ['attributes' => [
                ['key' => 'service.version', 'value' => ['stringValue' => 'build-42']],
                ['key' => 'service.name', 'value' => ['stringValue' => 'api']],
                ['key' => 'service.namespace', 'value' => ['stringValue' => 'store']],
            ]],
            $scopeKey => [[$recordKey => [$record]]],
        ]]];

        $this->postJson(route('api.otlp', ['signal' => $signal]), $payload)->assertOk();

        $release = Release::sole();
        $this->assertSame('build-42', $release->version);
        $this->assertSame('api', $release->service);
        $this->assertSame('store', $release->service_namespace);
        $this->assertSame($release->id, TelemetryEvent::sole()->release_id);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function otlpSignals(): array
    {
        return [
            'traces' => ['traces', 'resourceSpans', 'scopeSpans', 'spans', [
                'name' => 'GET /', 'traceId' => str_repeat('a', 32), 'spanId' => str_repeat('b', 16),
                'startTimeUnixNano' => '1789992000000000000', 'endTimeUnixNano' => '1789992000001000000',
            ]],
            'logs' => ['logs', 'resourceLogs', 'scopeLogs', 'logRecords', ['body' => ['stringValue' => 'release log']]],
            'metrics' => ['metrics', 'resourceMetrics', 'scopeMetrics', 'metrics', [
                'name' => 'requests', 'gauge' => ['dataPoints' => [['asInt' => '1']]],
            ]],
        ];
    }

    public function test_worker_failure_rolls_back_release_time_bounds_and_event_links(): void
    {
        $environment = $this->collector();
        $release = Release::factory()->for($environment->project)->create([
            'version' => 'v1', 'service' => null, 'first_seen_at' => '2026-09-21T10:00:00Z', 'last_seen_at' => '2026-09-21T10:00:00Z',
        ]);
        DB::unprepared("CREATE TRIGGER reject_release_usage BEFORE INSERT ON telemetry_usage_entries BEGIN SELECT RAISE(ABORT, 'meter unavailable'); END");
        Exceptions::fake();

        $this->postJson(route('api.ingest'), ['batch_id' => 'rollback', 'events' => [
            ['type' => 'log', 'timestamp' => '2026-09-21T12:00:00Z', 'attributes' => ['service.version' => 'v1']],
            ['type' => 'log', 'attributes' => ['service.version' => 'v2']],
        ]])->assertInternalServerError();

        Exceptions::assertReported(QueryException::class);
        $this->assertDatabaseCount('releases', 1);
        $this->assertSame('2026-09-21T10:00:00.000000Z', $this->reload($release)->last_seen_at?->toISOString());
        foreach (['telemetry_events', 'telemetry_usage_entries'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_queued_version_linking_finishes_after_monitoring_is_turned_off(): void
    {
        config(['monitoring.telemetry.queue_connection' => 'telemetry']);
        $environment = $this->collector();
        $this->postJson(route('api.ingest'), ['batch_id' => 'queued-release', 'events' => [[
            'type' => 'log', 'attributes' => ['service.version' => 'queued-v1'],
        ]]])->assertAccepted();
        $environment->project->enabledServices()->where('service', 'monitoring')->delete();

        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'telemetry', '--queue' => 'telemetry', '--once' => true, '--sleep' => 0]));

        $this->assertSame(Release::sole()->id, TelemetryEvent::sole()->release_id);
        $this->assertDatabaseCount('telemetry_usage_entries', 1);
    }

    public function test_batch_release_queries_do_not_grow_with_repeated_records_of_one_version(): void
    {
        $this->collector();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, '"releases"')) {
                $queries[] = $query->sql;
            }
        });
        $events = array_fill(0, 50, ['type' => 'log', 'attributes' => ['service.version' => 'batch-version']]);

        $this->postJson(route('api.ingest'), ['batch_id' => 'batch-release-queries', 'events' => $events])->assertOk()->assertJsonPath('data.accepted', 50);

        $this->assertLessThanOrEqual(4, count($queries));
        $this->assertDatabaseCount('releases', 1);
        $this->assertSame(50, Release::sole()->telemetryEvents()->count());
    }

    public function test_otlp_record_version_without_a_resource_version_does_not_create_a_release(): void
    {
        $this->collector();

        $this->postJson(route('api.otlp', ['signal' => 'logs']), ['resourceLogs' => [[
            'scopeLogs' => [['logRecords' => [[
                'body' => ['stringValue' => 'unversioned'],
                'attributes' => [['key' => 'service.version', 'value' => ['stringValue' => 'record-only']]],
            ]]]],
        ]]])->assertOk();

        $this->assertDatabaseEmpty('releases');
        $this->assertNull(TelemetryEvent::sole()->release_id);
    }

    private function collector(?Environment $environment = null): Environment
    {
        $environment ??= Environment::factory()->create();
        $secret = 'release-collector-'.$environment->id;
        IngestToken::factory()->for($environment)->withSecret($secret)->create();
        $this->withToken($secret);

        return $environment;
    }
}
