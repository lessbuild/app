<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Actions\Monitoring\RecordHeartbeat;
use App\Models\HeartbeatRun;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class HeartbeatIngestionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_start_and_completion_are_correlated_and_retries_do_not_extend_deadlines_or_duplicate_outcomes(): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = Monitor::factory()->heartbeat()->create();
        $runId = fake()->uuid();
        $url = route('api.heartbeats.store', ['heartbeat' => $monitor->id]);
        $this->withToken('test-heartbeat-key');

        $this->postJson($url, ['run_id' => $runId, 'signal' => 'start'])->assertOk()
            ->assertExactJson(['data' => ['run_id' => $runId, 'signal' => 'start', 'replayed' => false, 'received_at' => '2026-09-21T10:00:00.000000Z']])
            ->assertHeader('Cache-Control', 'no-store, private');
        foreach (['monitor_checks', 'incidents', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->travel(2)->minutes();
        $this->postJson($url, ['run_id' => strtoupper($runId), 'signal' => 'start'])->assertJsonPath('data.replayed', true);
        $this->assertSame('2026-09-21 10:05:00', $this->reload($monitor)->next_check_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-21 10:00:00', $this->reload($monitor)->heartbeat_received_at?->format('Y-m-d H:i:s'));
        $this->postJson($url, ['run_id' => $runId, 'signal' => 'success'])->assertJsonPath('data.replayed', false);
        $this->travel(1)->minute();
        $this->postJson($url, ['run_id' => $runId, 'signal' => 'success'])->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.received_at', '2026-09-21T10:02:00.000000Z');

        $this->assertDatabaseCount('heartbeat_runs', 1);
        $this->assertSame('success', HeartbeatRun::query()->sole()->status);
        $this->assertSame(120000.0, $monitor->checks()->sole()->duration_ms);
        $this->assertSame('up', $this->reload($monitor)->health);
        $this->assertSame('2026-09-21 11:07:00', $this->reload($monitor)->next_check_at?->format('Y-m-d H:i:s'));
        foreach (['incidents', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_completion_only_heartbeat_does_not_invent_a_duration(): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = Monitor::factory()->heartbeat()->create();
        $this->travel(30)->minutes();

        $this->withToken('test-heartbeat-key')->postJson(route('api.heartbeats.store', ['heartbeat' => $monitor->id]),
            ['run_id' => fake()->uuid(), 'signal' => 'success'])->assertOk();

        $this->assertNull($monitor->checks()->sole()->duration_ms);
        $this->assertSame('2026-09-21T11:05:00.000000Z', ($monitor->checks()->sole()->details ?? [])['deadline_at']);
        $this->assertNull($monitor->heartbeatRuns()->sole()->started_at);
        $this->assertSame('up', $this->reload($monitor)->health);
    }

    #[DataProvider('unavailableSources')]
    public function test_returns_401_for_an_invalid_key_or_unavailable_source_without_recording_a_signal(string $change): void
    {
        $monitor = Monitor::factory()->heartbeat()->create();
        $key = 'test-heartbeat-key';
        match ($change) {
            'wrong key' => $key = 'not-the-correct-key',
            'no key' => $key = '',
            'paused monitor' => $monitor->forceFill(['enabled' => false])->save(),
            'revoked key' => $monitor->forceFill(['heartbeat_token_hash' => null])->save(),
            'archived monitor' => $monitor->delete(),
            'monitoring turned off' => $monitor->environment->project->enabledServices()->where('service', 'monitoring')->delete(),
            'deleted project' => $monitor->environment->project->delete(),
            'wrong type' => $monitor->forceFill(['type' => 'dns'])->save(),
            default => throw new LogicException($change),
        };

        $this->withToken($key)->postJson(route('api.heartbeats.store', ['heartbeat' => $monitor->id]),
            ['run_id' => fake()->uuid(), 'signal' => 'success'])->assertUnauthorized();

        foreach (['heartbeat_runs', 'monitor_checks', 'incidents', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /** @return array<string, list<mixed>> */
    public static function unavailableSources(): array
    {
        $cases = ['wrong key', 'no key', 'paused monitor', 'revoked key', 'archived monitor', 'monitoring turned off', 'deleted project', 'wrong type'];

        return array_combine($cases, array_map(fn (string $case): array => [$case], $cases));
    }

    public function test_a_key_cannot_write_to_another_monitor_or_an_unknown_monitor_and_a_session_is_not_a_key(): void
    {
        $monitor = Monitor::factory()->heartbeat()->create();
        $other = Monitor::factory()->heartbeat()->create(['heartbeat_token_hash' => hash('sha256', 'different-heartbeat-key')]);
        $payload = ['run_id' => fake()->uuid(), 'signal' => 'success'];

        $this->withToken('test-heartbeat-key')->postJson(route('api.heartbeats.store', ['heartbeat' => $other->id]), $payload)->assertUnauthorized();
        $this->postJson(route('api.heartbeats.store', ['heartbeat' => 999999]), $payload)->assertUnauthorized();
        $this->flushHeaders()->actingAs($this->ownerOf($monitor))
            ->postJson(route('api.heartbeats.store', ['heartbeat' => $monitor->id]), $payload)->assertUnauthorized();

        foreach (['heartbeat_runs', 'monitor_checks', 'incidents'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    #[DataProvider('invalidSignals')]
    public function test_returns_422_for_invalid_signal_data_without_writes(string $field, mixed $value): void
    {
        $monitor = Monitor::factory()->heartbeat()->create();
        $payload = ['run_id' => fake()->uuid(), 'signal' => 'success'];
        $payload[$field] = $value;

        $this->withToken('test-heartbeat-key')->postJson(route('api.heartbeats.store', ['heartbeat' => $monitor->id]), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field === 'received_at' ? 'payload' : $field);

        foreach (['heartbeat_runs', 'monitor_checks', 'incidents', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /** @return array<string, list<mixed>> */
    public static function invalidSignals(): array
    {
        return [
            'missing UUID' => ['run_id', null], 'invalid UUID' => ['run_id', 'repeated-job-name'],
            'UUID array' => ['run_id', ['nested']], 'padded UUID' => ['run_id', ' 123e4567-e89b-42d3-a456-426614174000 '],
            'missing signal' => ['signal', null], 'unknown signal' => ['signal', 'up'],
            'signal array' => ['signal', ['success']], 'client time' => ['received_at', '2020-01-01'],
        ];
    }

    public function test_query_parameters_cannot_supply_signal_fields_or_credentials(): void
    {
        $monitor = Monitor::factory()->heartbeat()->create();
        $url = route('api.heartbeats.store', ['heartbeat' => $monitor->id, 'run_id' => fake()->uuid(), 'signal' => 'success']);

        $this->withToken('test-heartbeat-key')->postJson($url, ['run_id' => null, 'signal' => null])->assertUnprocessable()->assertJsonValidationErrors(['run_id', 'signal']);
        $this->flushHeaders()->postJson($url.'&token=test-heartbeat-key', ['run_id' => fake()->uuid(), 'signal' => 'success'])->assertUnauthorized();

        $this->assertDatabaseEmpty('heartbeat_runs');
    }

    #[DataProvider('invalidBodies')]
    public function test_rejects_unsupported_or_unbounded_request_bodies(string $body, string $contentType, string $encoding, int $status): void
    {
        $monitor = Monitor::factory()->heartbeat()->create();

        $this->call('POST', route('api.heartbeats.store', ['heartbeat' => $monitor->id]), [], [], [], [
            'CONTENT_TYPE' => $contentType, 'HTTP_CONTENT_ENCODING' => $encoding,
            'HTTP_AUTHORIZATION' => 'Bearer test-heartbeat-key',
        ], $body)->assertStatus($status);

        foreach (['heartbeat_runs', 'monitor_checks', 'incidents'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /** @return array<string, list<mixed>> */
    public static function invalidBodies(): array
    {
        return [
            'actual byte bound' => [str_repeat(' ', 2049), 'application/json', 'identity', 413],
            'malformed JSON' => ['{"signal":', 'application/json', 'identity', 400],
            'array root' => ['[]', 'application/json', 'identity', 400],
            'nested JSON' => ['{"a":{"b":{"c":{"d":1}}}}', 'application/json', 'identity', 400],
            'form encoding' => ['signal=success', 'application/x-www-form-urlencoded', 'identity', 415],
            'gzip not supported' => ['{}', 'application/json', 'gzip', 415],
        ];
    }

    public function test_conflicting_terminal_signals_return_409_without_overwriting_the_first_result(): void
    {
        $monitor = Monitor::factory()->heartbeat()->create();
        $runId = fake()->uuid();
        $url = route('api.heartbeats.store', ['heartbeat' => $monitor->id]);
        $this->withToken('test-heartbeat-key')->postJson($url, ['run_id' => $runId, 'signal' => 'failure'])->assertOk();

        $this->postJson($url, ['run_id' => $runId, 'signal' => 'success'])->assertConflict();
        $this->postJson($url, ['run_id' => $runId, 'signal' => 'start'])->assertConflict();
        $this->postJson($url, ['run_id' => $runId, 'signal' => 'failure'])->assertJsonPath('data.replayed', true);

        $this->assertSame('failed', $monitor->heartbeatRuns()->sole()->status);
        $this->assertSame('down', $this->reload($monitor)->health);
        $this->assertDatabaseCount('monitor_checks', 1);
        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_transaction_rechecks_a_rotated_key_before_accepting_a_signal(): void
    {
        $monitor = Monitor::factory()->heartbeat()->create(['heartbeat_token_hash' => hash('sha256', 'replacement-heartbeat-key')]);

        try {
            app(RecordHeartbeat::class)->handle($monitor->id, hash('sha256', 'test-heartbeat-key'), fake()->uuid(), 'success');
            $this->fail('Expected key revalidation.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }

        foreach (['heartbeat_runs', 'monitor_checks', 'incidents', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_retries_are_rate_limited_per_monitor(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->heartbeat()->create();
        $payload = ['run_id' => fake()->uuid(), 'signal' => 'success'];
        $url = route('api.heartbeats.store', ['heartbeat' => $monitor->id]);
        $this->withToken('test-heartbeat-key');

        for ($index = 0; $index < 60; $index++) {
            $this->postJson($url, $payload)->assertOk();
        }
        $this->postJson($url, $payload)->assertTooManyRequests();

        $this->assertDatabaseCount('heartbeat_runs', 1);
        $this->assertDatabaseCount('monitor_checks', 1);
        $this->assertStringNotContainsString('test-heartbeat-key', (string) json_encode(DB::table('heartbeat_runs')->get()));
    }

    public function test_an_earlier_configuration_cannot_be_replayed_as_a_new_healthy_run(): void
    {
        $monitor = Monitor::factory()->heartbeat()->create(['config_revision' => 1]);
        $run = HeartbeatRun::factory()->for($monitor)->create(['config_revision' => 0]);

        $this->withToken('test-heartbeat-key')->postJson(route('api.heartbeats.store', ['heartbeat' => $monitor->id]),
            ['run_id' => $run->run_id, 'signal' => 'success'])->assertConflict();

        $this->assertSame('unknown', $this->reload($monitor)->health);
        $this->assertNull($this->reload($run)->terminal_signal);
        $this->assertDatabaseEmpty('monitor_checks');
    }

    public function test_per_monitor_rate_limits_are_isolated_and_invalid_keys_do_not_consume_them(): void
    {
        $this->freezeTime();
        $first = Monitor::factory()->heartbeat()->create();
        $second = Monitor::factory()->heartbeat()->create(['heartbeat_token_hash' => hash('sha256', 'second-heartbeat-key')]);
        $payload = ['run_id' => fake()->uuid(), 'signal' => 'success'];
        $firstUrl = route('api.heartbeats.store', ['heartbeat' => $first->id]);
        $secondUrl = route('api.heartbeats.store', ['heartbeat' => $second->id]);

        for ($index = 0; $index < 60; $index++) {
            $this->withToken('test-heartbeat-key')->postJson($firstUrl, $payload)->assertOk();
            $this->postJson($secondUrl, $payload)->assertUnauthorized();
        }
        $this->postJson($firstUrl, $payload)->assertTooManyRequests();
        $this->withToken('second-heartbeat-key')->postJson($secondUrl, $payload)->assertOk();

        $this->assertDatabaseCount('heartbeat_runs', 2);
        $this->assertDatabaseCount('monitor_checks', 2);
    }

    public function test_rejects_more_than_one_hundred_inflight_runs_but_accepts_completion_of_an_existing_run(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->heartbeat()->create();
        $runs = HeartbeatRun::factory()->count(100)->for($monitor)->create();
        $url = route('api.heartbeats.store', ['heartbeat' => $monitor->id]);

        $this->withToken('test-heartbeat-key')->postJson($url, ['run_id' => fake()->uuid(), 'signal' => 'start'])->assertTooManyRequests();
        $this->postJson($url, ['run_id' => $runs->firstOrFail()->run_id, 'signal' => 'success'])->assertOk();

        $this->assertSame(99, $monitor->heartbeatRuns()->where('status', 'running')->count());
        $this->assertDatabaseCount('heartbeat_runs', 100);
    }
}
