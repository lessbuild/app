<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Actions\Monitoring\RecordQueueSnapshot;
use App\Actions\Monitoring\RecordQueueWorker;
use App\Models\Monitor;
use App\Models\QueueSnapshot;
use App\Models\QueueWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class QueueSignalIngestionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_snapshot_receipts_are_retry_safe_and_canonicalize_equivalent_timestamps(): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = Monitor::factory()->queueMonitor()->create();
        QueueWorker::factory()->for($monitor)->create();
        $data = $this->snapshot();
        $url = route('api.queues.snapshots.store', ['queue' => $monitor->id]);
        $this->withToken('test-queue-monitor-key');

        $this->postJson($url, $data)->assertOk()->assertExactJson(['data' => [
            'snapshot_id' => $data['snapshot_id'], 'replayed' => false, 'applied' => true, 'received_at' => '2026-09-21T10:00:00.000000Z',
        ]])->assertHeader('Cache-Control', 'no-store, private');
        $this->travel(2)->minutes();
        $this->postJson($url, array_replace($data, ['observed_at' => '2026-09-21T10:00:00.000Z']))
            ->assertJsonPath('data.replayed', true)->assertJsonPath('data.received_at', '2026-09-21T10:00:00.000000Z');

        $snapshot = QueueSnapshot::query()->sole();
        $this->assertSame('2026-09-21 10:03:00', $snapshot->valid_until->format('Y-m-d H:i:s'));
        $this->assertSame($snapshot->id, $this->reload($monitor)->queue_snapshot_id);
        $this->assertSame('up', $this->reload($monitor)->health);
        $this->assertDatabaseCount('monitor_checks', 1);
        $this->assertArrayNotHasKey('payload_hash', $snapshot->toArray());
        foreach (['incidents', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_worker_sequences_are_idempotent_and_cannot_renew_liveness_or_reset_the_same_busy_job(): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = Monitor::factory()->queueMonitor()->create();
        $data = ['worker_id' => fake()->uuid(), 'sequence' => 1, 'status' => 'busy', 'job_id' => fake()->uuid()];
        $url = route('api.queues.workers.store', ['queue' => $monitor->id]);
        $this->withToken('test-queue-monitor-key');

        $this->postJson($url, $data)->assertOk()->assertExactJson(['data' => [
            'worker_id' => $data['worker_id'], 'sequence' => 1, 'status' => 'busy', 'replayed' => false, 'received_at' => '2026-09-21T10:00:00.000000Z',
        ]]);
        $this->travel(30)->seconds();
        $this->postJson($url, $data)->assertJsonPath('data.replayed', true);
        $this->assertSame('2026-09-21 10:00:00', QueueWorker::query()->sole()->last_seen_at->format('Y-m-d H:i:s'));
        $this->postJson($url, array_replace($data, ['sequence' => 2]))->assertJsonPath('data.replayed', false);

        $worker = QueueWorker::query()->sole();
        $this->assertSame('2026-09-21 10:00:00', $worker->job_started_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-21 10:00:30', $worker->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, $worker->last_sequence);
        $this->assertDatabaseCount('monitor_checks', 1);
        foreach (['incidents', 'jobs', 'queue_snapshots'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    #[DataProvider('unavailableSources')]
    public function test_returns_401_for_invalid_credentials_or_unavailable_sources_without_writes(string $change): void
    {
        $monitor = Monitor::factory()->queueMonitor()->create();
        $key = 'test-queue-monitor-key';
        match ($change) {
            'wrong key' => $key = 'incorrect-queue-monitor-key', 'no key' => $key = '',
            'paused monitor' => $monitor->forceFill(['enabled' => false])->save(),
            'revoked key' => $monitor->forceFill(['queue_token_hash' => null])->save(),
            'archived monitor' => $monitor->delete(),
            'monitoring turned off' => $monitor->environment->project->enabledServices()->where('service', 'monitoring')->delete(),
            'deleted project' => $monitor->environment->project->delete(),
            'wrong type' => $monitor->forceFill(['type' => 'http'])->save(),
            default => throw new LogicException($change),
        };

        $this->withToken($key)->postJson(route('api.queues.snapshots.store', ['queue' => $monitor->id]), $this->snapshot())->assertUnauthorized();
        $this->postJson(route('api.queues.workers.store', ['queue' => $monitor->id]),
            ['worker_id' => fake()->uuid(), 'sequence' => 1, 'status' => 'idle'])->assertUnauthorized();

        foreach (['queue_snapshots', 'queue_workers', 'monitor_checks', 'incidents', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /** @return array<string, list<mixed>> */
    public static function unavailableSources(): array
    {
        $cases = ['wrong key', 'no key', 'paused monitor', 'revoked key', 'archived monitor', 'monitoring turned off', 'deleted project', 'wrong type'];

        return array_combine($cases, array_map(fn (string $case): array => [$case], $cases));
    }

    public function test_keys_cannot_cross_monitors_and_browser_sessions_cannot_submit_signals(): void
    {
        $monitor = Monitor::factory()->queueMonitor()->create();
        $other = Monitor::factory()->queueMonitor()->create(['queue_token_hash' => hash('sha256', 'another-queue-collector-key')]);

        $this->withToken('test-queue-monitor-key')->postJson(route('api.queues.snapshots.store', ['queue' => $other->id]), $this->snapshot())->assertUnauthorized();
        $this->postJson(route('api.queues.snapshots.store', ['queue' => 999999]), $this->snapshot())->assertUnauthorized();
        $this->flushHeaders()->actingAs($this->ownerOf($monitor))
            ->postJson(route('api.queues.snapshots.store', ['queue' => $monitor->id]), $this->snapshot())->assertUnauthorized();

        foreach (['queue_snapshots', 'queue_workers', 'monitor_checks'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    #[DataProvider('invalidSnapshots')]
    public function test_invalid_snapshot_data_returns_422_without_history_or_health_changes(string $field, mixed $value): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = Monitor::factory()->queueMonitor()->create();

        $this->withToken('test-queue-monitor-key')->postJson(route('api.queues.snapshots.store', ['queue' => $monitor->id]),
            array_replace($this->snapshot(), [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);

        foreach (['queue_snapshots', 'queue_workers', 'monitor_checks', 'incidents', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertSame('unknown', $this->reload($monitor)->health);
    }

    /** @return array<string, list<mixed>> */
    public static function invalidSnapshots(): array
    {
        return ['missing UUID' => ['snapshot_id', null], 'invalid UUID' => ['snapshot_id', 'abc'], 'missing time' => ['observed_at', null],
            'invalid time' => ['observed_at', 'tomorrow'], 'non-UTC time' => ['observed_at', '2026-09-21T10:00:00+01:00'],
            'overflow date' => ['observed_at', '2026-02-30T10:00:00Z'], 'too old' => ['observed_at', '1999-01-01T00:00:00Z'],
            'future clock' => ['observed_at', '2026-09-21T10:00:31Z'], 'missing pending' => ['pending', null],
            'negative count' => ['pending', -1], 'float count' => ['pending', 1.5], 'string count' => ['pending', '12'],
            'boolean count' => ['pending', true], 'oversized count' => ['pending', 1000000001],
            'invalid failed count' => ['failed', -1], 'invalid delayed count' => ['delayed', '5'],
            'invalid reserved count' => ['reserved', []], 'negative oldest age' => ['oldest_wait_seconds', -1]];
    }

    #[DataProvider('invalidWorkers')]
    public function test_invalid_worker_signals_return_422_without_creating_worker_records(string $field, mixed $value): void
    {
        $monitor = Monitor::factory()->queueMonitor()->create();
        $data = ['worker_id' => fake()->uuid(), 'sequence' => 1, 'status' => 'busy', 'job_id' => fake()->uuid()];

        $this->withToken('test-queue-monitor-key')->postJson(route('api.queues.workers.store', ['queue' => $monitor->id]),
            array_replace($data, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);

        foreach (['queue_workers', 'monitor_checks', 'incidents'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /** @return array<string, list<mixed>> */
    public static function invalidWorkers(): array
    {
        return ['missing worker' => ['worker_id', null], 'invalid worker' => ['worker_id', 'host'], 'missing sequence' => ['sequence', null],
            'zero sequence' => ['sequence', 0], 'large sequence' => ['sequence', 2147483648], 'string sequence' => ['sequence', '1'],
            'boolean sequence' => ['sequence', true], 'unknown status' => ['status', 'working'],
            'missing busy job' => ['job_id', null], 'invalid job' => ['job_id', 'email-job']];
    }

    public function test_unknown_fields_query_injection_and_impossible_empty_queue_age_are_rejected(): void
    {
        $monitor = Monitor::factory()->queueMonitor()->create();
        $url = route('api.queues.snapshots.store', ['queue' => $monitor->id]);
        $this->withToken('test-queue-monitor-key');

        $this->postJson($url, $this->snapshot() + ['job_payload' => 'secret'])->assertUnprocessable()->assertJsonValidationErrors('payload');
        $this->postJson($url.'?'.http_build_query($this->snapshot()), ['unused' => null])->assertUnprocessable()->assertJsonValidationErrors(['pending', 'snapshot_id', 'observed_at']);
        $this->postJson($url, array_replace($this->snapshot(), ['pending' => 0, 'oldest_wait_seconds' => 5]))
            ->assertUnprocessable()->assertJsonValidationErrors(['oldest_wait_seconds' => 'An empty ready queue cannot have a positive oldest-job wait.']);
        $this->postJson(route('api.queues.workers.store', ['queue' => $monitor->id]),
            ['worker_id' => fake()->uuid(), 'sequence' => 1, 'status' => 'idle', 'job_id' => fake()->uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('job_id');

        foreach (['queue_snapshots', 'queue_workers', 'monitor_checks'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    #[DataProvider('invalidBodies')]
    public function test_queue_signals_reject_unsupported_or_unbounded_bodies(string $body, string $contentType, string $encoding, int $status): void
    {
        $monitor = Monitor::factory()->queueMonitor()->create();

        $this->call('POST', route('api.queues.snapshots.store', ['queue' => $monitor->id]), [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer test-queue-monitor-key', 'CONTENT_TYPE' => $contentType, 'HTTP_CONTENT_ENCODING' => $encoding,
        ], $body)->assertStatus($status);

        foreach (['queue_snapshots', 'monitor_checks'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /** @return array<string, list<mixed>> */
    public static function invalidBodies(): array
    {
        return ['form' => ['pending=1', 'application/x-www-form-urlencoded', 'identity', 415],
            'gzip' => ['{}', 'application/json', 'gzip', 415], 'large' => [str_repeat(' ', 2049), 'application/json', 'identity', 413],
            'bad JSON' => ['{', 'application/json', 'identity', 400], 'array' => ['[]', 'application/json', 'identity', 400],
            'scalar' => ['true', 'application/json', 'identity', 400], 'deep' => ['{"a":{"b":{"c":{"d":1}}}}', 'application/json', 'identity', 400]];
    }

    public function test_conflicting_snapshot_ids_and_worker_sequences_return_409_without_overwriting_evidence(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->queueMonitor()->create();
        $snapshot = $this->snapshot();
        $worker = ['worker_id' => fake()->uuid(), 'sequence' => 2, 'status' => 'idle'];
        $this->withToken('test-queue-monitor-key');
        $url = route('api.queues.snapshots.store', ['queue' => $monitor->id]);
        $workerUrl = route('api.queues.workers.store', ['queue' => $monitor->id]);
        $this->postJson($url, $snapshot)->assertOk();
        $this->postJson($workerUrl, $worker)->assertOk();

        $this->postJson($url, array_replace($snapshot, ['pending' => 999]))->assertConflict();
        $this->postJson($url, array_replace($snapshot, ['snapshot_id' => fake()->uuid()]))->assertConflict();
        $this->postJson($workerUrl, array_replace($worker, ['sequence' => 1]))->assertConflict();
        $this->postJson($workerUrl, array_replace($worker, ['status' => 'stopped']))->assertConflict();

        $this->assertSame(5, QueueSnapshot::query()->sole()->pending);
        $this->assertSame('idle', QueueWorker::query()->sole()->status);
    }

    public function test_snapshot_limits_are_per_monitor_and_worker_signals_have_a_separate_budget(): void
    {
        $this->freezeTime();
        $first = Monitor::factory()->queueMonitor()->create();
        $second = Monitor::factory()->queueMonitor()->create();
        $data = $this->snapshot();
        $this->withToken('test-queue-monitor-key');
        $url = route('api.queues.snapshots.store', ['queue' => $first->id]);

        for ($i = 0; $i < 60; $i++) {
            $this->postJson($url, $data)->assertOk();
        }
        $this->postJson($url, $data)->assertTooManyRequests();
        $this->postJson(route('api.queues.snapshots.store', ['queue' => $second->id]), $this->snapshot())->assertOk();
        $this->postJson(route('api.queues.workers.store', ['queue' => $first->id]),
            ['worker_id' => fake()->uuid(), 'sequence' => 1, 'status' => 'idle'])->assertOk();

        $this->assertDatabaseCount('queue_snapshots', 2);
        $this->assertDatabaseCount('queue_workers', 1);
    }

    public function test_live_worker_capacity_is_bounded_without_rejecting_existing_heartbeats_or_discarding_inactive_history(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->queueMonitor()->create();
        $workers = QueueWorker::factory()->count(100)->for($monitor)->create();
        $url = route('api.queues.workers.store', ['queue' => $monitor->id]);
        $new = ['worker_id' => fake()->uuid(), 'sequence' => 1, 'status' => 'idle'];
        $this->withToken('test-queue-monitor-key');

        $this->postJson($url, $new)->assertTooManyRequests();
        $this->postJson($url, ['worker_id' => $workers->firstOrFail()->worker_id, 'sequence' => 2, 'status' => 'idle'])->assertOk();
        $this->postJson($url, ['worker_id' => $workers->last()?->worker_id, 'sequence' => 2, 'status' => 'stopped'])->assertOk();
        $this->postJson($url, $new)->assertOk();

        $this->assertDatabaseCount('queue_workers', 101);
        $this->assertSame('stopped', $this->reload($workers->last() ?? new QueueWorker)->status);
        $this->assertModelExists($workers->last() ?? new QueueWorker);
    }

    public function test_worker_signals_ignore_query_fields_and_reject_client_timestamps(): void
    {
        $monitor = Monitor::factory()->queueMonitor()->create();
        $url = route('api.queues.workers.store', ['queue' => $monitor->id]);
        $data = ['worker_id' => fake()->uuid(), 'sequence' => 1, 'status' => 'idle'];
        $this->withToken('test-queue-monitor-key');

        $this->postJson($url.'?'.http_build_query($data), ['unused' => null])->assertUnprocessable()->assertJsonValidationErrors(['worker_id', 'sequence', 'status']);
        $this->postJson($url, $data + ['last_seen_at' => now('UTC')->addYear()->toISOString()])->assertUnprocessable()->assertJsonValidationErrors('payload');

        foreach (['queue_workers', 'monitor_checks'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    #[DataProvider('signalKinds')]
    public function test_signal_transactions_recheck_rotated_keys_before_any_write(string $kind): void
    {
        $monitor = Monitor::factory()->queueMonitor()->create();
        $oldHash = $monitor->queue_token_hash;
        $monitor->forceFill(['queue_token_hash' => hash('sha256', 'new-collector-key')])->save();

        try {
            if ($kind === 'snapshot') {
                app(RecordQueueSnapshot::class)->handle($monitor->id, (string) $oldHash, $this->snapshot());
            } else {
                app(RecordQueueWorker::class)->handle($monitor->id, (string) $oldHash, ['worker_id' => fake()->uuid(), 'sequence' => 1, 'status' => 'idle']);
            }
            $this->fail('Expected the transaction to reject the old key.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }

        foreach (['queue_snapshots', 'queue_workers', 'monitor_checks', 'incidents', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /** @return array<string, list<mixed>> */
    public static function signalKinds(): array
    {
        return ['snapshot' => ['snapshot'], 'worker' => ['worker']];
    }

    public function test_invalid_credentials_do_not_consume_another_monitors_authenticated_budget(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->queueMonitor()->create();
        $url = route('api.queues.snapshots.store', ['queue' => $monitor->id]);
        $data = $this->snapshot();
        $this->withToken('not-the-queue-monitor-key');

        for ($i = 0; $i < 61; $i++) {
            $this->postJson($url, $data)->assertUnauthorized();
        }
        $this->withToken('test-queue-monitor-key')->postJson($url, $data)->assertOk();

        $this->assertDatabaseCount('queue_snapshots', 1);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return ['snapshot_id' => fake()->uuid(), 'observed_at' => now('UTC')->toISOString(),
            'pending' => 5, 'delayed' => 0, 'reserved' => 1, 'failed' => 0, 'oldest_wait_seconds' => 10];
    }
}
