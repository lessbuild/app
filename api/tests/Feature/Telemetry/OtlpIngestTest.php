<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Models\IngestToken;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class OtlpIngestTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_it_normalizes_otlp_traces_into_telemetry_events(): void
    {
        $token = 'otlp-traces-token';
        $project = Project::factory()->withServices(['monitoring'])->create();
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        IngestToken::factory()->for($environment)->withSecret($token)->create();

        $payload = [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => [
                        ['key' => 'service.name', 'value' => ['stringValue' => 'node-api']],
                    ],
                ],
                'scopeSpans' => [[
                    'spans' => [[
                        'traceId' => '4bf92f3577b34da6a3ce929d0e0e4736',
                        'spanId' => '00f067aa0ba902b7',
                        'name' => 'GET /orders',
                        'startTimeUnixNano' => '1700000000000000000',
                        'endTimeUnixNano' => '1700000000042000000',
                        'attributes' => [
                            ['key' => 'http.route', 'value' => ['stringValue' => '/orders']],
                            ['key' => 'http.response.status_code', 'value' => ['intValue' => '200']],
                        ],
                        'status' => ['code' => 1],
                    ]],
                ]],
            ]],
        ];

        $response = $this->withToken($token)->postJson(route('api.otlp', ['signal' => 'traces']), $payload);

        $response->assertOk()
            ->assertHeader('X-Beacon-Accepted', '1')
            ->assertContent('{}');

        $this->assertDatabaseHas('telemetry_events', [
            'type' => 'request',
            'service' => 'node-api',
            'route' => '/orders',
            'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
        ]);

        $this->withToken($token)
            ->postJson(route('api.otlp', ['signal' => 'traces']), $payload)
            ->assertOk()
            ->assertHeader('X-Beacon-Accepted', '0')
            ->assertHeader('X-Beacon-Duplicates', '1');
    }

    public function test_it_accepts_otlp_logs_and_metrics(): void
    {
        $token = 'otlp-signals-token';
        $project = Project::factory()->withServices(['monitoring'])->create();
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        IngestToken::factory()->for($environment)->withSecret($token)->create();

        $this->withToken($token)
            ->postJson(route('api.otlp', ['signal' => 'logs']), [
                'resourceLogs' => [[
                    'resource' => ['attributes' => [
                        ['key' => 'service.name', 'value' => ['stringValue' => 'python-worker']],
                    ]],
                    'scopeLogs' => [[
                        'logRecords' => [[
                            'timeUnixNano' => '1700000000000000000',
                            'severityText' => 'ERROR',
                            'body' => ['stringValue' => 'Worker failed to process invoice'],
                        ]],
                    ]],
                ]],
            ])
            ->assertOk()
            ->assertHeader('X-Beacon-Accepted', '1')
            ->assertContent('{}');

        $this->withToken($token)
            ->postJson(route('api.otlp', ['signal' => 'metrics']), [
                'resourceMetrics' => [[
                    'resource' => ['attributes' => [
                        ['key' => 'service.name', 'value' => ['stringValue' => 'python-worker']],
                    ]],
                    'scopeMetrics' => [[
                        'metrics' => [[
                            'name' => 'queue.depth',
                            'gauge' => ['dataPoints' => [[
                                'timeUnixNano' => '1700000000000000000',
                                'asInt' => '7',
                            ]]],
                        ]],
                    ]],
                ]],
            ])
            ->assertOk()
            ->assertHeader('X-Beacon-Accepted', '1')
            ->assertContent('{}');

        $this->assertDatabaseHas('telemetry_events', [
            'type' => 'log',
            'service' => 'python-worker',
        ]);
        $this->assertDatabaseHas('telemetry_events', [
            'type' => 'metric',
            'name' => 'queue.depth',
            'service' => 'python-worker',
        ]);
    }
}
