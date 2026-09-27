<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Contracts\Telemetry\TelemetryIngestor;
use App\Models\IngestToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class OtlpProtocolTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    #[DataProvider('emptyExports')]
    public function test_empty_exports_return_an_empty_success_object_without_recording_activity(string $signal, string $json): void
    {
        $token = IngestToken::factory()->withSecret('empty-export-key')->create();

        $this->call('POST', route('api.otlp', $signal), server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer empty-export-key',
        ], content: $json)->assertOk()->assertContent('{}')->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-Beacon-Accepted', '0')->assertHeader('X-Beacon-Duplicates', '0');

        $this->assertDatabaseCount('telemetry_events', 0);
        $this->assertSame(0, $token->environment->telemetry_event_count);
        $this->assertNull($token->environment->telemetry_last_received_at);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function emptyExports(): array
    {
        return [
            'empty traces' => ['traces', '{}'],
            'empty logs' => ['logs', '{}'],
            'empty metrics' => ['metrics', '{}'],
            'empty resource list' => ['traces', '{"resourceSpans":[]}'],
            'null resource list' => ['logs', '{"resourceLogs":null}'],
            'unknown future field' => ['metrics', '{"futureField":{"anything":"ignored"}}'],
        ];
    }

    public function test_a_valid_export_returns_only_the_standard_success_message(): void
    {
        IngestToken::factory()->withSecret('success-export-key')->create();

        $this->withToken('success-export-key')->postJson(route('api.otlp', 'logs'), [
            'resourceLogs' => [['scopeLogs' => [['logRecords' => [['body' => ['stringValue' => 'Accepted']]]]]]],
        ])->assertOk()->assertContent('{}')->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-Beacon-Accepted', '1')->assertHeader('X-Beacon-Duplicates', '0');

        $this->assertDatabaseHas('telemetry_events', ['name' => 'Accepted', 'type' => 'log']);
    }

    public function test_invalid_data_uses_a_status_message_with_bad_request_details(): void
    {
        IngestToken::factory()->withSecret('validation-export-key')->create();

        $this->withToken('validation-export-key')->postJson(route('api.otlp', 'logs'), [
            'resourceLogs' => 'invalid',
        ])->assertBadRequest()->assertExactJson([
            'code' => 3,
            'message' => 'The OTLP payload is invalid.',
            'details' => [[
                '@type' => 'type.googleapis.com/google.rpc.BadRequest',
                'fieldViolations' => [
                    ['field' => 'resourceLogs', 'description' => 'The resource logs field must be an array.'],
                    ['field' => 'resourceLogs', 'description' => 'The resource logs field must be a list.'],
                ],
            ]],
        ]);

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_decode_failures_use_the_same_status_envelope_without_echoing_payload_data(): void
    {
        $this->call('POST', route('api.otlp', 'traces'), server: ['CONTENT_TYPE' => 'application/json'], content: '{"private-value":')
            ->assertBadRequest()->assertExactJson([
                'code' => 3, 'message' => 'Telemetry request must contain valid UTF-8 JSON.',
            ]);

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_rate_limit_responses_keep_retry_after_and_use_a_status_message(): void
    {
        RateLimiter::for('ingest', fn () => Limit::perMinute(1)->by('protocol-rate-limit'));
        IngestToken::factory()->withSecret('rate-export-key')->create();
        $payload = ['resourceLogs' => []];

        $this->withToken('rate-export-key')->postJson(route('api.otlp', 'logs'), $payload)->assertOk();
        $response = $this->postJson(route('api.otlp', 'logs'), $payload);

        $response->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('code', 8);
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame(['code', 'message'], array_keys($response->json()));
        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_unexpected_failures_do_not_expose_exception_details_in_protocol_responses(): void
    {
        IngestToken::factory()->withSecret('failure-export-key')->create();
        Exceptions::fake();
        $this->mock(TelemetryIngestor::class)->shouldReceive('ingest')->once()->andThrow(new RuntimeException('private database details'));

        $this->withToken('failure-export-key')->postJson(route('api.otlp', 'logs'), [
            'resourceLogs' => [],
        ])->assertInternalServerError()->assertExactJson([
            'code' => 13, 'message' => 'Telemetry could not be accepted.',
        ]);

        Exceptions::assertReported(RuntimeException::class);
        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_bad_batch_headers_are_rejected_before_any_records_are_stored(): void
    {
        IngestToken::factory()->withSecret('batch-header-key')->create();

        $this->withToken('batch-header-key')->withHeader('X-Beacon-Batch', str_repeat('x', 101))
            ->postJson(route('api.otlp', 'logs'), ['resourceLogs' => []])
            ->assertBadRequest()->assertJsonPath('details.0.fieldViolations.0.field', 'X-Beacon-Batch');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_validation_details_are_bounded_even_when_many_records_are_invalid(): void
    {
        IngestToken::factory()->withSecret('bounded-errors-key')->create();

        $response = $this->withToken('bounded-errors-key')->postJson(route('api.otlp', 'traces'), [
            'resourceSpans' => [['scopeSpans' => [['spans' => array_fill(0, 30, ['name' => 'Missing fields'])]]]],
        ]);

        $response->assertBadRequest()->assertJsonCount(20, 'details.0.fieldViolations');
        $this->assertDatabaseCount('telemetry_events', 0);
    }

    #[DataProvider('signals')]
    public function test_even_empty_exports_require_valid_authentication(string $signal): void
    {
        $this->call('POST', route('api.otlp', $signal), server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer invalid-export-key',
        ], content: '{}')->assertUnauthorized()->assertExactJson(['message' => 'The ingestion token is invalid.']);

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function signals(): array
    {
        return ['traces' => ['traces'], 'logs' => ['logs'], 'metrics' => ['metrics']];
    }
}
