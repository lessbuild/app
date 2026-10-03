<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Enums\IngestStatus;
use App\Enums\SelectionKind;
use App\Jobs\Telemetry\ProcessQueuedTelemetry;
use App\Models\BillingSelection;
use App\Models\Environment;
use App\Models\IngestPayload;
use App\Models\IngestReceipt;
use App\Models\IngestToken;
use App\Models\Issue;
use App\Models\TelemetryEvent;
use App\Models\TelemetryUsageEntry;
use App\Services\Telemetry\ProcessTelemetryReceipt;
use App\Services\Telemetry\TelemetryQueue;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class QueuedTelemetryTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['monitoring.telemetry.queue_connection' => 'telemetry']);
    }

    public function test_returns_202_only_after_redacted_encrypted_payload_and_job_are_retained(): void
    {
        $token = $this->collector();

        $response = $this->postJson(route('api.ingest'), ['batch_id' => 'private-delivery', 'events' => [[
            'id' => 'private-event-id', 'type' => 'log', 'name' => 'password=private-secret',
            'attributes' => ['password' => 'attribute-secret', 'region' => 'eu'],
        ]]])->assertAccepted()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.accepted', 1);

        $receipt = IngestReceipt::sole();
        $response->assertJsonPath('data.receipt_id', $receipt->id);
        $this->assertSame(IngestStatus::Queued, $receipt->status);
        foreach (['telemetry_events', 'issues', 'telemetry_usage_entries'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('telemetry_event_identities', ['ingest_receipt_id' => $receipt->id, 'telemetry_event_id' => null]);
        $this->assertSame(0, $token->environment->telemetry_event_count);
        $payload = IngestPayload::sole();
        $this->assertSame('[REDACTED]', ($payload->payload ?? [])[0]['event']['attributes']['password']);
        $this->assertArrayNotHasKey('id', ($payload->payload ?? [])[0]['event']);
        $this->assertStringNotContainsString('private-secret', (string) json_encode($payload->payload));
        $this->assertStringNotContainsString('region', $payload->getRawOriginal('payload'));
        $this->assertArrayNotHasKey('payload', $payload->toArray());
        $job = DB::table('jobs')->sole();
        $this->assertSame($receipt->queue_job_uuid, $job->job_uuid);
        $this->assertStringContainsString($receipt->id, $job->payload);
        $this->assertStringNotContainsString('private-event-id', $job->payload);
        $this->assertStringNotContainsString('attribute-secret', $job->payload);
        $this->getJson(route('api.ingest.receipts.show', $receipt))->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.processing.attempts', 0)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_worker_meters_once_in_the_arrival_month_despite_delayed_processing_and_replays(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30T23:59:59.123456Z'));
        $token = $this->collector();
        $delivery = ['batch_id' => 'month-boundary', 'events' => [['id' => 'one', 'type' => 'exception', 'name' => 'Failure']]];
        $this->postJson(route('api.ingest'), $delivery)->assertAccepted();
        $receipt = IngestReceipt::sole();
        $this->postJson(route('api.ingest'), $delivery)->assertAccepted()->assertJsonPath('data.replayed', true)->assertJsonPath('data.accepted', 0);
        $this->assertDatabaseCount('jobs', 1);
        $this->travel(2)->seconds();

        $this->workOne();
        Queue::connection('telemetry')->push(new ProcessQueuedTelemetry($receipt->id, 1), '', 'telemetry');
        $this->workOne();
        app(ProcessTelemetryReceipt::class)->failed($receipt->id, 1);

        $this->assertSame(IngestStatus::Completed, $receipt->refresh()->status);
        $this->assertSame(1, $receipt->processing_attempts);
        foreach (['ingest_payloads', 'jobs', 'failed_jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertDatabaseCount('telemetry_events', 1);
        $this->assertDatabaseCount('telemetry_usage_entries', 1);
        $this->assertSame('2026-09-30 23:59:59.123456', TelemetryUsageEntry::sole()->received_at->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-09-30 23:59:59.123456', TelemetryEvent::sole()->occurred_at->format('Y-m-d H:i:s.u'));
        $this->assertSame(1, Issue::sole()->occurrences);
        $this->assertSame(1, $token->environment->refresh()->telemetry_event_count);
        $this->postJson(route('api.ingest'), $delivery)->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_monthly_plan_limit_retains_over_limit_delivery_for_retry_after_upgrade(): void
    {
        $token = $this->collector();
        $account = $token->environment->project->account;
        TelemetryUsageEntry::factory()->for($account)->create(['event_count' => 499_999, 'received_at' => now('UTC')]);
        $this->enqueue('within-limit');
        $this->workOne();
        $overLimit = $this->enqueue('over-limit');

        $this->workOne();

        $this->assertSame(IngestStatus::Failed, $overLimit->refresh()->status);
        $this->assertSame('plan_limit_reached', $overLimit->last_error_code);
        $this->assertDatabaseCount('telemetry_events', 1);
        $this->assertDatabaseCount('telemetry_usage_entries', 2);
        $this->assertDatabaseCount('ingest_payloads', 1);

        $selection = new BillingSelection;
        $selection->forceFill(['account_id' => $account->id, 'service' => 'monitoring', 'kind' => SelectionKind::Tier, 'item_key' => 'pro', 'quantity' => 1])->save();
        $this->assertTrue(app(TelemetryQueue::class)->retry($overLimit->id));
        $this->workOne();

        $this->assertSame(IngestStatus::Completed, $overLimit->refresh()->status);
        $this->assertDatabaseCount('telemetry_events', 2);
        $this->assertDatabaseCount('telemetry_usage_entries', 3);
        $this->assertDatabaseEmpty('ingest_payloads');
    }

    public function test_transient_failure_rolls_back_all_processing_and_retains_a_stable_retry_identity(): void
    {
        $this->freezeTime();
        $token = $this->collector();
        $receipt = $this->enqueue();
        $originalJobId = $receipt->queue_job_uuid;
        $this->rejectInserts('telemetry_usage_entries', 'reject_usage', 'meter unavailable');
        Exceptions::fake();

        $this->workOne();

        Exceptions::assertReported(QueryException::class);
        $this->assertSame(IngestStatus::Retrying, $receipt->refresh()->status);
        $this->assertSame('storage_unavailable', $receipt->last_error_code);
        foreach (['telemetry_events', 'issues', 'telemetry_usage_entries'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertSame(0, $token->environment->refresh()->telemetry_event_count);
        $this->assertDatabaseCount('ingest_payloads', 1);
        $this->assertNotSame($originalJobId, DB::table('jobs')->sole()->id);
        $this->assertSame($receipt->queue_job_uuid, DB::table('jobs')->sole()->job_uuid);

        $this->travel(5)->seconds();
        $this->assertSame(0, Artisan::call('telemetry:recover'));
        $this->assertStringContainsString('Recovered 0 ingestion deliveries.', Artisan::output());
        $this->assertSame(1, $receipt->refresh()->generation);
        $this->allowInserts('telemetry_usage_entries', 'reject_usage');
        $this->workOne();

        $this->assertSame(IngestStatus::Completed, $receipt->refresh()->status);
        $this->assertSame(2, $receipt->processing_attempts);
        $this->assertSame(1, $token->environment->refresh()->telemetry_event_count);
        $this->assertSame(1, Issue::sole()->occurrences);
        $this->assertSame(1, TelemetryUsageEntry::sole()->event_count);
        foreach (['ingest_payloads', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_exhausted_retries_can_be_requeued_without_stale_jobs_overriding_the_new_attempt(): void
    {
        $this->freezeTime();
        $this->collector();
        $receipt = $this->enqueue();
        $this->rejectInserts('telemetry_usage_entries', 'reject_usage', 'meter unavailable');
        Exceptions::fake();

        foreach ([0, 5, 30, 120, 300] as $delay) {
            $this->travel($delay)->seconds();
            $this->workOne();
        }

        Exceptions::assertReported(QueryException::class);
        $this->assertSame(IngestStatus::Failed, $receipt->refresh()->status);
        $this->assertSame(5, $receipt->processing_attempts);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('ingest_payloads', 1);
        foreach (['jobs', 'telemetry_events', 'telemetry_usage_entries', 'issues'] as $table) {
            $this->assertDatabaseEmpty($table);
        }

        $this->allowInserts('telemetry_usage_entries', 'reject_usage');
        $this->assertTrue(app(TelemetryQueue::class)->retry($receipt->id));
        app(ProcessTelemetryReceipt::class)->failed($receipt->id, 1);
        app(ProcessTelemetryReceipt::class)->process($receipt->id, 1);
        $this->assertSame(IngestStatus::Queued, $receipt->refresh()->status);
        $this->assertSame(2, $receipt->generation);
        $this->workOne();

        $this->assertSame(IngestStatus::Completed, $receipt->refresh()->status);
        $this->assertSame(1, TelemetryUsageEntry::sole()->event_count);
        $this->assertFalse(app(TelemetryQueue::class)->retry($receipt->id));
    }

    public function test_returns_500_without_accepting_data_if_the_durable_job_cannot_be_written(): void
    {
        $this->collector();
        $this->rejectInserts('jobs', 'reject_job', 'queue unavailable');
        Exceptions::fake();

        $this->postJson(route('api.ingest'), ['batch_id' => 'queue-down', 'events' => [['type' => 'log']]])->assertInternalServerError();

        Exceptions::assertReported(QueryException::class);
        foreach (['ingest_receipts', 'ingest_payloads', 'jobs', 'telemetry_event_identities', 'telemetry_events', 'telemetry_usage_entries'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_lost_processing_jobs_are_recovered_only_after_the_lease_expires(): void
    {
        $this->freezeTime();
        $this->collector();
        $receipt = $this->enqueue();
        DB::table('jobs')->where('job_uuid', $receipt->queue_job_uuid)->delete();
        $receipt->forceFill(['status' => IngestStatus::Processing, 'processing_attempts' => 1, 'next_attempt_at' => now()->addSeconds(180)])->save();

        $this->assertSame(0, app(TelemetryQueue::class)->recover());
        $this->travel(181)->seconds();
        $this->assertSame(1, app(TelemetryQueue::class)->recover());
        app(ProcessTelemetryReceipt::class)->failed($receipt->id, 1);
        $this->workOne();

        $this->assertSame(IngestStatus::Completed, $receipt->refresh()->status);
        $this->assertSame(2, $receipt->generation);
        $this->assertSame(2, $receipt->processing_attempts);
        $this->assertSame(1, $receipt->recovery_count);
        $this->assertDatabaseCount('telemetry_usage_entries', 1);
    }

    public function test_healthy_jobs_do_not_starve_lost_deliveries_when_recovery_is_limited(): void
    {
        $this->freezeTime();
        $this->collector();
        $healthy = $this->enqueue('healthy');
        $lost = $this->enqueue('lost');
        DB::table('jobs')->where('job_uuid', $lost->queue_job_uuid)->delete();

        $this->assertSame(0, Artisan::call('telemetry:recover', ['--limit' => 1]));
        $this->assertStringContainsString('Recovered 1 ingestion deliveries.', Artisan::output());

        $this->assertSame(1, $healthy->refresh()->generation);
        $this->assertSame(2, $lost->refresh()->generation);
        $this->assertDatabaseCount('jobs', 2);
    }

    #[DataProvider('unreadablePayloads')]
    public function test_unreadable_payloads_fail_without_charges_or_automatic_retry_loops(string $mode): void
    {
        $this->collector();
        $receipt = $this->enqueue();
        match ($mode) {
            'missing' => $receipt->ingestPayload()->delete(),
            'corrupt' => DB::table('ingest_payloads')->update(['payload' => 'not-ciphertext']),
            'wrong-count' => $receipt->forceFill(['accepted_count' => 2])->save(),
            default => throw new LogicException($mode),
        };

        $this->workOne();

        $this->assertSame(IngestStatus::Failed, $receipt->refresh()->status);
        $this->assertSame('payload_unavailable', $receipt->last_error_code);
        foreach (['jobs', 'telemetry_events', 'telemetry_usage_entries'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertSame(0, app(TelemetryQueue::class)->recover());
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function unreadablePayloads(): array
    {
        return ['missing' => ['missing'], 'corrupt' => ['corrupt'], 'wrong count' => ['wrong-count']];
    }

    #[DataProvider('disabledSources')]
    public function test_accepted_deliveries_finish_after_new_ingestion_is_disabled(string $mode): void
    {
        $token = $this->collector();
        $receipt = $this->enqueue();
        match ($mode) {
            'token' => $token->forceFill(['revoked_at' => now()])->save(),
            'monitoring off' => $token->environment->project->enabledServices()->where('service', 'monitoring')->delete(),
            default => throw new LogicException($mode),
        };

        $this->workOne();

        $this->assertSame(IngestStatus::Completed, $receipt->refresh()->status);
        $this->assertDatabaseCount('telemetry_events', 1);
        $this->assertDatabaseCount('telemetry_usage_entries', 1);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function disabledSources(): array
    {
        return ['revoked token' => ['token'], 'monitoring off' => ['monitoring off']];
    }

    public function test_permanently_removed_sources_discard_pending_payloads_without_usage(): void
    {
        $token = $this->collector();
        $receipt = $this->enqueue();
        $token->environment->forceDelete();

        $this->workOne();

        $this->assertSame(IngestStatus::Failed, $receipt->refresh()->status);
        $this->assertSame('source_removed', $receipt->last_error_code);
        foreach (['ingest_payloads', 'telemetry_events', 'telemetry_usage_entries', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_otlp_keeps_its_success_response_while_signals_are_processed_in_background(): void
    {
        $this->collector();

        $this->postJson(route('api.otlp', 'logs'), ['resourceLogs' => [['scopeLogs' => [['logRecords' => [
            ['body' => ['stringValue' => 'Background OTLP log']],
        ]]]]]])->assertOk()->assertContent('{}')->assertHeader('X-Beacon-Status', 'queued');
        $this->assertDatabaseEmpty('telemetry_events');
        $this->workOne();

        $this->assertDatabaseHas('telemetry_events', ['type' => 'log', 'name' => 'Background OTLP log']);
        $this->assertDatabaseHas('telemetry_usage_entries', ['source' => 'otlp_logs', 'event_count' => 1]);
    }

    /**
     * @param  array<mixed>  $configuration
     */
    #[DataProvider('unsafeQueueConfigurations')]
    public function test_unsafe_queue_configurations_return_500_without_retaining_partial_data(array $configuration): void
    {
        $this->collector();
        config(['queue.connections.telemetry' => $configuration]);
        Exceptions::fake();

        $this->postJson(route('api.ingest'), ['batch_id' => 'unsafe', 'events' => [['type' => 'log']]])->assertInternalServerError();

        Exceptions::assertReported(LogicException::class);
        foreach (['ingest_receipts', 'ingest_payloads', 'telemetry_event_identities', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function unsafeQueueConfigurations(): array
    {
        return [
            'ephemeral' => [['driver' => 'deferred']],
            'other database' => [['driver' => 'database', 'connection' => 'elsewhere', 'table' => 'jobs', 'retry_after' => 180]],
            'other table' => [['driver' => 'database', 'table' => 'elsewhere', 'retry_after' => 180]],
            'short reservation' => [['driver' => 'database', 'table' => 'jobs', 'retry_after' => 60]],
        ];
    }

    public function test_delayed_older_errors_do_not_regress_issue_times_or_latest_severity(): void
    {
        $token = $this->collector();
        $this->postJson(route('api.ingest'), ['batch_id' => 'newer', 'events' => [[
            'id' => 'newer', 'type' => 'exception', 'name' => 'Same failure', 'fingerprint' => 'shared',
            'timestamp' => '2026-09-20T12:00:00Z', 'severity' => 'critical',
        ]]])->assertAccepted();
        $olderEnvironment = Environment::factory()->for($token->environment->project)->create(['slug' => 'staging']);
        IngestToken::factory()->for($olderEnvironment)->withSecret('older-key')->create();
        $this->withToken('older-key')->postJson(route('api.ingest'), ['batch_id' => 'older', 'events' => [[
            'id' => 'older', 'type' => 'exception', 'name' => 'Same failure', 'fingerprint' => 'shared',
            'timestamp' => '2026-09-19T12:00:00Z', 'severity' => 'error',
        ]]])->assertAccepted();

        $this->workOne();
        $this->workOne();

        $issue = Issue::sole();
        $this->assertSame('2026-09-19 12:00:00', $issue->first_seen_at->toDateTimeString());
        $this->assertSame('2026-09-20 12:00:00', $issue->last_seen_at->toDateTimeString());
        $this->assertSame('critical', $issue->severity);
        $this->assertSame($token->environment_id, $issue->environment_id);
        $this->assertSame(2, $issue->occurrences);
    }

    public function test_early_deliveries_wait_until_due_without_using_a_processing_attempt(): void
    {
        $this->freezeTime();
        $this->collector();
        $receipt = $this->enqueue();
        $receipt->forceFill(['next_attempt_at' => now()->addSeconds(10)])->save();

        $this->workOne();

        $this->assertSame(0, $receipt->refresh()->processing_attempts);
        $this->assertSame(IngestStatus::Queued, $receipt->status);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseEmpty('telemetry_usage_entries');
        $this->travel(10)->seconds();
        $this->workOne();
        $this->assertSame(IngestStatus::Completed, $receipt->refresh()->status);
        $this->assertSame(1, $receipt->processing_attempts);
    }

    public function test_recovery_stops_retrying_interrupted_deliveries_after_five_processing_attempts(): void
    {
        $this->freezeTime();
        $this->collector();
        $receipt = $this->enqueue();
        DB::table('jobs')->where('job_uuid', $receipt->queue_job_uuid)->delete();
        $receipt->forceFill(['processing_attempts' => 5, 'status' => IngestStatus::Processing, 'next_attempt_at' => now()])->save();

        $this->assertSame(0, app(TelemetryQueue::class)->recover());

        $this->assertSame(IngestStatus::Failed, $receipt->refresh()->status);
        $this->assertSame('worker_interrupted', $receipt->last_error_code);
        $this->assertDatabaseCount('ingest_payloads', 1);
        foreach (['jobs', 'telemetry_usage_entries'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_recovery_without_a_retained_payload_marks_the_delivery_failed(): void
    {
        $this->freezeTime();
        $this->collector();
        $receipt = $this->enqueue();
        DB::table('jobs')->where('job_uuid', $receipt->queue_job_uuid)->delete();
        $receipt->ingestPayload()->delete();

        $this->assertSame(0, app(TelemetryQueue::class)->recover());

        $this->assertSame(IngestStatus::Failed, $receipt->refresh()->status);
        $this->assertSame('payload_unavailable', $receipt->last_error_code);
        foreach (['jobs', 'telemetry_usage_entries'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    #[DataProvider('invalidRecoveryLimits')]
    public function test_recovery_rejects_invalid_limits(string $limit): void
    {
        $this->assertSame(2, Artisan::call('telemetry:recover', ['--limit' => $limit]));
        $this->assertStringContainsString('The limit must be an integer between 1 and 1000.', Artisan::output());
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function invalidRecoveryLimits(): array
    {
        return ['zero' => ['0'], 'too high' => ['1001'], 'not an integer' => ['no-limit']];
    }

    public function test_development_processes_include_the_dedicated_worker_scheduler_and_requested_port(): void
    {
        $commands = array_column(DevCommands::commands(), 'command', 'name');

        $this->assertSame('php artisan queue:listen telemetry --queue=telemetry --tries=5 --timeout=60', $commands['telemetry']);
        $this->assertSame('php artisan schedule:work --no-interaction', $commands['scheduler']);
        $this->assertSame('php artisan queue:listen checks --queue=checks --tries=1 --timeout=45', $commands['checks']);
        $this->assertSame('php artisan queue:listen alerts --queue=alerts --tries=1 --timeout=45', $commands['alerts']);
    }

    public function test_a_processing_lease_prevents_a_second_worker_claiming_the_delivery(): void
    {
        $this->freezeTime();
        $this->collector();
        $receipt = $this->enqueue();
        $receipt->forceFill(['status' => IngestStatus::Processing, 'processing_attempts' => 1, 'next_attempt_at' => now()->addSeconds(180)])->save();

        $this->assertNull(app(ProcessTelemetryReceipt::class)->process($receipt->id, $receipt->generation));

        $this->assertSame(IngestStatus::Processing, $receipt->refresh()->status);
        $this->assertSame(1, $receipt->processing_attempts);
        $this->assertDatabaseEmpty('telemetry_usage_entries');
        $this->assertDatabaseCount('ingest_payloads', 1);
    }

    private function collector(): IngestToken
    {
        $token = IngestToken::factory()->withSecret('queued-test-key')->create();
        $this->withToken('queued-test-key');

        return $token;
    }

    private function enqueue(string $batch = 'background'): IngestReceipt
    {
        $id = $this->postJson(route('api.ingest'), ['batch_id' => $batch, 'events' => [
            ['id' => 'event', 'type' => 'exception', 'name' => 'Failure'],
        ]])->assertAccepted()->json('data.receipt_id');

        return IngestReceipt::query()->whereKey($id)->firstOrFail();
    }

    private function workOne(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'telemetry', '--queue' => 'telemetry', '--once' => true, '--sleep' => 0]));

    }
}
