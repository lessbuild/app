<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Contracts\Telemetry\TelemetryIngestor;
use App\Contracts\Telemetry\TelemetryPayloadMapper;
use App\Data\Telemetry\IngestContext;
use App\Models\Environment;
use App\Models\IngestReceipt;
use App\Models\IngestToken;
use App\Models\Issue;
use App\Models\TelemetryEvent;
use App\Models\TelemetryUsageEntry;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class IngestReceiptTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_a_delivery_creates_a_private_receipt_and_meters_only_new_events(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00.123456Z'));
        $environment = $this->authenticateCollector();
        $event = ['id' => 'request-one', 'type' => 'log', 'name' => 'Sensitive password=secret-value'];

        $response = $this->postJson(route('api.ingest'), [
            'batch_id' => 'private-batch-name', 'events' => [$event, $event],
            'accepted_count' => 500, 'account_id' => 999, 'source' => 'otlp_metrics',
        ])->assertOk()->assertJsonPath('data.accepted', 1)->assertJsonPath('data.duplicates', 1)
            ->assertJsonPath('data.replayed', false);

        $receipt = IngestReceipt::sole();
        $response->assertJsonPath('data.receipt_id', $receipt->id);
        $this->assertDatabaseHas('telemetry_usage_entries', [
            'ingest_receipt_id' => $receipt->id, 'account_id' => $environment->project->account_id,
            'environment_id' => $environment->id, 'event_count' => 1, 'source' => 'json',
            'received_at' => '2026-09-21 12:00:00.123456',
        ]);
        $this->assertDatabaseCount('telemetry_event_identities', 1);
        $this->assertSame(1, $environment->refresh()->telemetry_event_count);

        $this->getJson(route('api.ingest.receipts.show', $receipt))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [
                'id' => $receipt->id, 'source' => 'json', 'status' => 'completed',
                'submitted' => 2, 'accepted' => 1, 'duplicates' => 1, 'attempts' => 1,
                'received_at' => '2026-09-21T12:00:00.123456Z',
                'last_received_at' => '2026-09-21T12:00:00.123456Z',
                'processed_at' => '2026-09-21T12:00:00.123456Z',
                'processing' => [
                    'attempts' => 1, 'recoveries' => 0, 'next_attempt_at' => null,
                    'failed_at' => null, 'error_code' => null, 'error_message' => null,
                ],
            ]]);

        $stored = json_encode(DB::table('ingest_receipts')->first()).json_encode(DB::table('telemetry_event_identities')->first());
        $this->assertStringNotContainsString('secret-value', $stored);
        $this->assertStringNotContainsString('private-batch-name', $stored);
        $this->assertArrayNotHasKey('payload_fingerprint', $receipt->toArray());
        $this->assertArrayNotHasKey('receipt_key', $receipt->toArray());
    }

    public function test_a_replay_keeps_the_original_receipt_counts_and_does_not_repeat_side_effects(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $environment = $this->authenticateCollector();
        $payload = ['batch_id' => 'retry', 'events' => [['id' => 'failure', 'type' => 'exception', 'name' => 'Failure']]];
        $original = $this->postJson(route('api.ingest'), $payload)->assertOk()->json('data.receipt_id');
        $this->travel(2)->minutes();

        $this->postJson(route('api.ingest'), $payload)->assertOk()->assertJsonPath('data.receipt_id', $original)
            ->assertJsonPath('data.accepted', 0)->assertJsonPath('data.duplicates', 1)->assertJsonPath('data.replayed', true);

        $this->assertDatabaseCount('ingest_receipts', 1);
        $this->assertDatabaseCount('telemetry_usage_entries', 1);
        $this->assertSame(1, Issue::sole()->occurrences);
        $this->assertSame(1, $environment->refresh()->telemetry_event_count);
        $this->assertSame('12:00:00', $environment->telemetry_last_received_at?->format('H:i:s'));
        $this->assertDatabaseHas('ingest_receipts', ['id' => $original, 'accepted_count' => 1, 'attempt_count' => 2, 'last_received_at' => '2026-09-21 12:02:00.000000']);
    }

    public function test_reusing_a_batch_with_changed_secret_content_returns_422_without_metering_it(): void
    {
        $this->authenticateCollector();
        $payload = ['batch_id' => 'same-batch', 'events' => [['id' => 'same-event', 'type' => 'log', 'attributes' => ['password' => 'one-secret']]]];
        $this->postJson(route('api.ingest'), $payload)->assertOk();
        $payload['events'][0]['attributes']['password'] = 'another-secret';

        $this->postJson(route('api.ingest'), $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['batch_id' => 'This identity was already used with different telemetry. Retry the original payload unchanged.']);

        $this->assertDatabaseCount('telemetry_events', 1);
        $this->assertDatabaseCount('telemetry_usage_entries', 1);
        $this->assertSame(1, IngestReceipt::sole()->attempt_count);
    }

    public function test_conflicting_event_ids_roll_back_the_entire_delivery_and_issue_aggregation(): void
    {
        $environment = $this->authenticateCollector();

        $this->postJson(route('api.ingest'), [
            'batch_id' => 'conflicting', 'events' => [
                ['id' => 'same', 'type' => 'exception', 'name' => 'First'],
                ['id' => 'same', 'type' => 'exception', 'name' => 'Second'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['events.1.id']);

        foreach (['telemetry_events', 'issues', 'telemetry_usage_entries', 'ingest_receipts', 'telemetry_event_identities'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertSame(0, $environment->refresh()->telemetry_event_count);
        $this->assertNull($environment->telemetry_last_received_at);
    }

    public function test_structured_identity_keys_do_not_confuse_delimiters_or_explicit_numeric_ids_with_positions(): void
    {
        $this->authenticateCollector();
        $this->postJson(route('api.ingest'), ['batch_id' => 'batch|part', 'events' => [['id' => 'event', 'type' => 'log']]])
            ->assertJsonPath('data.accepted', 1);
        $this->postJson(route('api.ingest'), ['batch_id' => 'batch', 'events' => [['id' => 'part|event', 'type' => 'log']]])
            ->assertJsonPath('data.accepted', 1);

        $this->postJson(route('api.ingest'), ['batch_id' => 'numeric', 'events' => [
            ['type' => 'log'], ['id' => '0', 'type' => 'log'], ['id' => '', 'type' => 'log'],
        ]])->assertJsonPath('data.accepted', 3)->assertJsonPath('data.duplicates', 0);

        $this->assertDatabaseCount('telemetry_events', 5);
        $this->assertSame(5, (int) TelemetryUsageEntry::sum('event_count'));
    }

    public function test_reordered_object_keys_and_redaction_policy_changes_do_not_break_retries(): void
    {
        $this->authenticateCollector();
        $this->postJson(route('api.ingest'), ['batch_id' => 'key-order', 'events' => [[
            'id' => 'one', 'type' => 'log', 'attributes' => ['nested' => ['z' => 2, 'a' => 1], 'custom' => 'value'],
        ]]])->assertOk();
        config(['monitoring.telemetry.redacted_paths' => ['attributes.custom']]);

        $this->postJson(route('api.ingest'), ['events' => [[
            'attributes' => ['custom' => 'value', 'nested' => ['a' => 1, 'z' => 2]], 'type' => 'log', 'id' => 'one',
        ]], 'batch_id' => 'key-order'])->assertJsonPath('data.replayed', true)->assertJsonPath('data.duplicates', 1);

        $this->assertDatabaseCount('telemetry_usage_entries', 1);
    }

    public function test_retries_survive_receipt_and_raw_event_deletion_using_retained_identities(): void
    {
        $this->authenticateCollector();
        $payload = ['batch_id' => 'retain-identity', 'events' => [['id' => 'one', 'type' => 'log']]];
        $this->postJson(route('api.ingest'), $payload)->assertOk();
        TelemetryEvent::sole()->delete();
        IngestReceipt::sole()->delete();

        $this->postJson(route('api.ingest'), $payload)->assertOk()->assertJsonPath('data.accepted', 0)->assertJsonPath('data.duplicates', 1);

        $this->assertDatabaseEmpty('telemetry_events');
        $this->assertDatabaseCount('telemetry_usage_entries', 1);
        $this->assertDatabaseHas('telemetry_event_identities', ['telemetry_event_id' => null, 'ingest_receipt_id' => null]);
        $this->assertSame(0, IngestReceipt::sole()->accepted_count);
    }

    public function test_implicit_otlp_exports_get_distinct_receipts_and_only_new_records_are_metered(): void
    {
        $this->authenticateCollector();
        $first = $this->postJson(route('api.otlp', 'logs'), $this->logs(['first']))->assertOk()->assertContent('{}')
            ->assertHeader('X-Beacon-Accepted', '1')->headers->get('X-Beacon-Receipt');

        $second = $this->postJson(route('api.otlp', 'logs'), $this->logs(['first', 'second']))->assertOk()->assertContent('{}')
            ->assertHeader('X-Beacon-Accepted', '1')->assertHeader('X-Beacon-Duplicates', '1')->headers->get('X-Beacon-Receipt');
        $this->assertNotSame($first, $second);
        $this->postJson(route('api.otlp', 'logs'), $this->logs(['first', 'second']))->assertHeader('X-Beacon-Receipt', $second)
            ->assertHeader('X-Beacon-Replayed', 'true')->assertHeader('X-Beacon-Duplicates', '2');

        $this->assertDatabaseCount('ingest_receipts', 2);
        $this->assertDatabaseCount('telemetry_events', 2);
        $this->assertSame(2, (int) TelemetryUsageEntry::sum('event_count'));
    }

    public function test_a_zero_otlp_batch_header_is_explicit_and_conflicts_return_a_protocol_error(): void
    {
        $this->authenticateCollector();
        $this->withHeader('X-Beacon-Batch', '0')->postJson(route('api.otlp', 'logs'), $this->logs(['first']))->assertOk();

        $this->postJson(route('api.otlp', 'logs'), $this->logs(['second']))->assertBadRequest()
            ->assertJsonPath('code', 3)->assertJsonPath('message', 'The OTLP payload is invalid.');

        $this->assertDatabaseCount('telemetry_events', 1);
        $this->assertDatabaseCount('ingest_receipts', 1);
        $this->assertDatabaseCount('telemetry_usage_entries', 1);
    }

    public function test_explicit_otlp_batch_ids_distinguish_new_identical_untimed_exports(): void
    {
        $this->authenticateCollector();
        $this->withHeader('X-Beacon-Batch', 'first-export')->postJson(route('api.otlp', 'logs'), $this->logs(['same']))->assertHeader('X-Beacon-Accepted', '1');

        $this->withHeader('X-Beacon-Batch', 'second-export')->postJson(route('api.otlp', 'logs'), $this->logs(['same']))->assertHeader('X-Beacon-Accepted', '1');

        $this->assertDatabaseCount('telemetry_events', 2);
        $this->assertSame(2, (int) TelemetryUsageEntry::sum('event_count'));
    }

    public function test_app_key_rotation_accepts_previous_fingerprints_for_explicit_and_implicit_receipts(): void
    {
        $this->authenticateCollector();
        $json = ['batch_id' => 'rotation', 'events' => [['id' => 'one', 'type' => 'log']]];
        $explicit = $this->postJson(route('api.ingest'), $json)->assertOk()->json('data.receipt_id');
        $implicit = $this->postJson(route('api.otlp', 'logs'), $this->logs(['rotate']))->assertOk()->headers->get('X-Beacon-Receipt');
        config(['app.previous_keys' => [config('app.key')], 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $this->postJson(route('api.ingest'), $json)->assertJsonPath('data.receipt_id', $explicit)->assertJsonPath('data.replayed', true);
        $this->postJson(route('api.otlp', 'logs'), $this->logs(['rotate']))->assertHeader('X-Beacon-Receipt', $implicit)->assertHeader('X-Beacon-Replayed', 'true');

        $this->assertDatabaseCount('telemetry_usage_entries', 2);
        $this->assertDatabaseCount('ingest_receipts', 2);
    }

    public function test_custom_otlp_batch_ids_cannot_collide_with_the_implicit_export_scope(): void
    {
        $this->authenticateCollector();
        $this->postJson(route('api.otlp', 'logs'), $this->logs(['same']))->assertHeader('X-Beacon-Accepted', '1');

        $this->withHeader('X-Beacon-Batch', 'otlp:logs')->postJson(route('api.otlp', 'logs'), $this->logs(['same']))
            ->assertHeader('X-Beacon-Accepted', '1')->assertHeader('X-Beacon-Duplicates', '0');

        $this->assertDatabaseCount('ingest_receipts', 2);
        $this->assertSame(2, (int) TelemetryUsageEntry::sum('event_count'));
    }

    public function test_json_and_otlp_have_separate_server_controlled_identity_namespaces(): void
    {
        $this->authenticateCollector();
        $logs = $this->logs(['same']);
        $mapped = app(TelemetryPayloadMapper::class)->map($logs, 'logs');
        $this->postJson(route('api.ingest'), ['batch_id' => 'same-batch', 'events' => $mapped])
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.accepted', 1);

        $this->withHeader('X-Beacon-Batch', 'same-batch')->postJson(route('api.otlp', 'logs'), $logs)
            ->assertHeader('X-Beacon-Accepted', '1');

        $this->assertDatabaseCount('telemetry_events', 2);
        $this->assertDatabaseHas('ingest_receipts', ['source' => 'json']);
        $this->assertDatabaseHas('ingest_receipts', ['source' => 'otlp_logs']);
    }

    public function test_new_batch_deduplication_uses_bounded_lookups_without_loading_stored_payloads(): void
    {
        $this->authenticateCollector();
        $events = array_map(fn (int $index): array => ['id' => 'event-'.$index, 'type' => 'log'], range(1, 50));
        DB::enableQueryLog();

        $this->postJson(route('api.ingest'), ['batch_id' => 'bulk', 'events' => $events])->assertJsonPath('data.accepted', 50);

        $lookups = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'select')
            && (str_contains($query['query'], '"telemetry_event_identities"') || str_contains($query['query'], '"telemetry_events"')));
        DB::disableQueryLog();
        $this->assertCount(2, $lookups);
        $this->assertDatabaseCount('telemetry_event_identities', 50);
        $this->assertSame(50, TelemetryUsageEntry::sole()->event_count);
    }

    public function test_receipts_are_not_created_for_empty_exports_or_rejected_payloads(): void
    {
        $this->authenticateCollector();

        $this->postJson(route('api.otlp', 'logs'), ['resourceLogs' => []])->assertOk()->assertHeaderMissing('X-Beacon-Receipt');
        $this->postJson(route('api.ingest'), ['batch_id' => 'invalid', 'events' => [['type' => 'invalid']]])
            ->assertUnprocessable();

        foreach (['ingest_receipts', 'telemetry_usage_entries', 'telemetry_event_identities', 'telemetry_events'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_trace_retries_accept_equivalent_encoding_but_reject_changed_span_content(): void
    {
        $this->authenticateCollector();
        $span = [
            'traceId' => '4bf92f3577b34da6a3ce929d0e0e4736', 'spanId' => '00f067aa0ba902b7',
            'name' => 'GET /health', 'startTimeUnixNano' => '1700000000000000000', 'endTimeUnixNano' => '1700000000042000000',
            'attributes' => [
                ['key' => 'first', 'value' => ['stringValue' => 'one']],
                ['key' => 'second', 'value' => ['stringValue' => 'two']],
            ],
        ];
        $payload = ['resourceSpans' => [['scopeSpans' => [['spans' => [$span]]]]]];
        $receiptId = $this->postJson(route('api.otlp', 'traces'), $payload)->assertOk()->headers->get('X-Beacon-Receipt');
        $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'] = array_reverse($span['attributes']);
        $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['startTimeUnixNano'] = 1700000000000000000;

        $this->postJson(route('api.otlp', 'traces'), $payload)->assertOk()->assertHeader('X-Beacon-Receipt', $receiptId)
            ->assertHeader('X-Beacon-Duplicates', '1');
        $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['name'] = 'Changed name';
        $this->postJson(route('api.otlp', 'traces'), $payload)->assertBadRequest()->assertJsonPath('code', 3);

        $this->assertDatabaseCount('ingest_receipts', 1);
        $this->assertDatabaseCount('telemetry_usage_entries', 1);
        $this->assertDatabaseHas('telemetry_events', ['name' => 'GET /health']);
    }

    public function test_an_accounting_write_failure_rolls_back_events_receipts_issues_and_counters(): void
    {
        $environment = $this->authenticateCollector();
        DB::unprepared("CREATE TRIGGER reject_usage BEFORE INSERT ON telemetry_usage_entries BEGIN SELECT RAISE(ABORT, 'meter unavailable'); END");
        Exceptions::fake();

        $this->postJson(route('api.ingest'), [
            'batch_id' => 'rollback', 'events' => [['id' => 'error', 'type' => 'exception', 'name' => 'Failure']],
        ])->assertInternalServerError();

        Exceptions::assertReported(QueryException::class);
        foreach (['telemetry_events', 'issues', 'telemetry_usage_entries', 'ingest_receipts', 'telemetry_event_identities'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertSame(0, $environment->refresh()->telemetry_event_count);
        $this->assertNull($environment->telemetry_last_received_at);
    }

    public function test_receipts_require_a_valid_active_environment_token(): void
    {
        $receipt = IngestReceipt::factory()->create();
        IngestToken::factory()->state(['environment_id' => $receipt->environment_id])->revoked()->withSecret('revoked')->create();

        $this->getJson(route('api.ingest.receipts.show', $receipt))->assertUnauthorized();
        $this->withToken('revoked')->getJson(route('api.ingest.receipts.show', $receipt))->assertUnauthorized();

        $this->assertSame(1, $receipt->refresh()->attempt_count);
    }

    public function test_receipts_are_hidden_from_other_environments_even_within_the_same_workspace(): void
    {
        $environment = $this->authenticateCollector();
        $sibling = Environment::factory()->for($environment->project)->create(['slug' => 'staging']);
        $receipt = IngestReceipt::factory()->for($sibling)->create();
        $foreign = IngestReceipt::factory()->create();

        $this->getJson(route('api.ingest.receipts.show', $receipt))->assertNotFound();
        $this->getJson(route('api.ingest.receipts.show', $foreign))->assertNotFound();
        $this->getJson(route('api.ingest.receipts.show', '00000000000000000000000000'))->assertNotFound();
        $this->getJson(route('api.ingest.receipts.show', 'not-a-receipt'))->assertNotFound();

        $this->assertSame(1, $receipt->refresh()->attempt_count);
    }

    public function test_receipt_reads_are_rate_limited_without_mutating_receipts(): void
    {
        $environment = $this->authenticateCollector();
        $receipt = IngestReceipt::factory()->for($environment)->create();
        RateLimiter::for('ingest', fn () => Limit::perMinute(1)->by('receipt-test'));

        $this->getJson(route('api.ingest.receipts.show', $receipt))->assertOk();
        $this->getJson(route('api.ingest.receipts.show', $receipt))->assertTooManyRequests();

        $this->assertSame(1, $receipt->refresh()->attempt_count);
    }

    public function test_ingestion_rechecks_a_revoked_token_inside_the_transaction(): void
    {
        $token = IngestToken::factory()->revoked()->create();
        $environment = $token->environment;

        try {
            app(TelemetryIngestor::class)->ingest($environment, 'revoked', [['type' => 'log']], new IngestContext(tokenId: $token->id));
            $this->fail('A revoked token was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }

        foreach (['telemetry_events', 'ingest_receipts', 'telemetry_usage_entries'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    private function authenticateCollector(): Environment
    {
        $token = IngestToken::factory()->withSecret('accounting-key')->create();
        $this->withToken('accounting-key');

        return $token->environment;
    }

    /** @param list<string> $messages
     * @return array<string, mixed>
     */
    private function logs(array $messages): array
    {
        return ['resourceLogs' => [['scopeLogs' => [[
            'logRecords' => array_map(fn (string $message): array => ['body' => ['stringValue' => $message]], $messages),
        ]]]]];
    }
}
