<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Models\IngestToken;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class TelemetryIngestTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_it_accepts_and_deduplicates_telemetry_batches(): void
    {
        $token = 'test-ingest-token';
        $project = Project::factory()->withServices(['monitoring'])->create();
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        IngestToken::factory()->for($environment)->withSecret($token)->create();

        $payload = [
            'batch_id' => 'batch-test-001',
            'events' => [
                [
                    'id' => 'request-001',
                    'type' => 'request',
                    'name' => 'GET /health',
                    'route' => '/health',
                    'service' => 'test-app',
                    'status_code' => 200,
                    'duration_ms' => 18,
                    'trace_id' => 'trace-test-001',
                    'timestamp' => '2026-09-20T20:30:00Z',
                ],
                [
                    'id' => 'exception-001',
                    'type' => 'exception',
                    'severity' => 'error',
                    'name' => 'Example exception',
                    'title' => 'Example exception',
                    'route' => '/health',
                    'trace_id' => 'trace-test-001',
                    'timestamp' => '2026-09-20T20:30:01Z',
                ],
            ],
        ];

        $this->withToken($token)
            ->postJson(route('api.ingest'), $payload)
            ->assertOk()
            ->assertJsonPath('data.batch_id', 'batch-test-001')
            ->assertJsonPath('data.accepted', 2)
            ->assertJsonPath('data.duplicates', 0);

        $this->assertDatabaseCount('telemetry_events', 2);
        $this->assertDatabaseHas('telemetry_events', [
            'type' => 'request',
            'trace_id' => 'trace-test-001',
        ]);
        $this->assertDatabaseHas('issues', [
            'title' => 'Example exception',
            'occurrences' => 1,
        ]);

        $this->withToken($token)
            ->postJson(route('api.ingest'), $payload)
            ->assertOk()
            ->assertJsonPath('data.accepted', 0)
            ->assertJsonPath('data.duplicates', 2);

        $this->assertDatabaseCount('telemetry_events', 2);
        $this->assertDatabaseHas('issues', [
            'title' => 'Example exception',
            'occurrences' => 1,
        ]);
    }

    public function test_it_rejects_requests_without_a_valid_ingest_token(): void
    {
        $this->postJson(route('api.ingest'), [
            'batch_id' => 'unauthorized-batch',
            'events' => [],
        ])->assertUnauthorized();

        $this->assertDatabaseCount('telemetry_events', 0);
    }
}
