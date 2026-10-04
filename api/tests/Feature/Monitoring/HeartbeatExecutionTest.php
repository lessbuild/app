<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Actions\Monitoring\RecordHeartbeat;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use LogicException;
use Tests\TestCase;

final class HeartbeatExecutionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * A missing heartbeat opens one incident without repeated deadline events or network jobs.
     */
    public function test_a_missing_heartbeat_opens_one_incident_without_repeated_deadline_events_or_network_jobs(): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = Monitor::factory()->heartbeat()->create();
        $destination = AlertDestination::factory()->for($monitor->environment->project->account)->create();
        $monitor->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        Http::preventStrayRequests();
        $scheduler = app(MonitorScheduler::class);
        $this->travel(64)->minutes();
        $this->assertSame(0, $scheduler->schedule());
        $this->travel(1)->minute();

        $this->assertSame(1, $scheduler->schedule());
        $this->assertSame(0, $scheduler->schedule());

        $this->assertSame('heartbeat_missing', $monitor->checks()->sole()->reason);
        $this->assertSame('down', $this->reload($monitor)->health);
        $this->assertNull($this->reload($monitor)->next_check_at);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertSame(['opened'], AlertDelivery::query()->pluck('event')->all());
        $this->assertSame(['alerts'], DB::table('jobs')->pluck('queue')->all());
        $this->assertDatabaseEmpty('heartbeat_runs');
        Http::assertNothingSent();
    }

    /**
     * A started run times out once and a late completion recovers the same run.
     */
    public function test_a_started_run_times_out_once_and_a_late_completion_recovers_the_same_run(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->heartbeat()->create();
        $runId = fake()->uuid();
        $this->signal($monitor, $runId, 'start');
        $this->travel(5)->minutes();

        $this->assertSame(1, app(MonitorScheduler::class)->schedule());

        $incident = $monitor->incidents()->sole();
        $this->assertSame('heartbeat_timeout', $monitor->checks()->sole()->reason);
        $this->assertSame('timed_out', $monitor->heartbeatRuns()->sole()->status);
        $this->assertSame('open', $incident->status);
        $this->travel(30)->seconds();
        $this->signal($monitor, $runId, 'success');

        $this->assertSame('success', $monitor->heartbeatRuns()->sole()->status);
        $this->assertSame('recovered', $this->reload($incident)->closure_reason);
        $this->assertSame('up', $this->reload($monitor)->health);
        $this->assertSame(['heartbeat_timeout', 'heartbeat_success'], $monitor->checks()->orderBy('id')->pluck('reason')->all());
        $this->assertSame(330000.0, $monitor->checks()->where('reason', 'heartbeat_success')->sole()->duration_ms);
    }

    /**
     * A late signal records the missed deadline even if the scheduler has not run.
     */
    public function test_a_late_signal_records_the_missed_deadline_even_if_the_scheduler_has_not_run(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->heartbeat()->create();
        $this->travel(66)->minutes();

        $this->signal($monitor, fake()->uuid(), 'success');

        $incident = $monitor->incidents()->sole();
        $this->assertSame('heartbeat_missing', $incident->opening_observation['reason']);
        $this->assertSame('recovered', $incident->closure_reason);
        $this->assertSame('up', $this->reload($monitor)->health);
        $this->assertDatabaseCount('monitor_checks', 2);
    }

    /**
     * Old overlapping completions cannot recover a newer failed run.
     */
    public function test_old_overlapping_completions_cannot_recover_a_newer_failed_run(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->heartbeat()->create();
        $destination = AlertDestination::factory()->for($monitor->environment->project->account)->create();
        $monitor->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        $older = fake()->uuid();
        $newer = fake()->uuid();
        $this->signal($monitor, $older, 'start');
        $this->signal($monitor, $newer, 'start');
        $this->signal($monitor, $newer, 'failure');
        $incident = $monitor->incidents()->sole();

        $this->signal($monitor, $older, 'success');

        $this->assertSame('down', $this->reload($monitor)->health);
        $this->assertSame('open', $this->reload($incident)->status);
        $this->assertFalse(($monitor->checks()->where('reason', 'heartbeat_success')->sole()->details ?? [])['affects_health']);
        $this->assertSame(['opened'], AlertDelivery::query()->pluck('event')->all());

        $this->signal($monitor, fake()->uuid(), 'success');

        $this->assertSame('recovered', $this->reload($incident)->closure_reason);
        $this->assertSame(['opened', 'recovered'], AlertDelivery::query()->orderBy('id')->pluck('event')->all());
        $payload = AlertDelivery::query()->where('event', 'opened')->sole()->payload;
        $this->assertSame('heartbeat', $payload['monitor']['type']);
        $this->assertStringNotContainsString('test-heartbeat-key', (string) json_encode($payload));
        $this->assertArrayNotHasKey('heartbeat_token_hash', $payload['monitor']);
    }

    /**
     * An older run timeout is retained without overriding a newer success.
     */
    public function test_an_older_run_timeout_is_retained_without_overriding_a_newer_success(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->heartbeat()->create();
        $this->signal($monitor, fake()->uuid(), 'start');
        $this->travel(1)->minute();
        $this->signal($monitor, fake()->uuid(), 'success');
        $this->travel(4)->minutes();

        app(MonitorScheduler::class)->schedule();

        $this->assertSame('up', $this->reload($monitor)->health);
        $this->assertDatabaseEmpty('incidents');
        $this->assertFalse(($monitor->checks()->where('reason', 'heartbeat_timeout')->sole()->details ?? [])['affects_health']);
        $this->assertSame('timed_out', $monitor->heartbeatRuns()->oldest('id')->firstOrFail()->status);
    }

    /**
     * Start retries do not hide an overdue run.
     */
    public function test_start_retries_do_not_hide_an_overdue_run(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->heartbeat()->create();
        $runId = fake()->uuid();
        $this->signal($monitor, $runId, 'start');
        $this->travel(6)->minutes();

        $receipt = $this->signal($monitor, $runId, 'start');
        app(MonitorScheduler::class)->schedule();

        $this->assertTrue($receipt['replayed']);
        $this->assertSame('down', $this->reload($monitor)->health);
        $this->assertSame('heartbeat_timeout', $monitor->checks()->sole()->reason);
    }

    /**
     * Run timeout and schedule deadline at the same time produce one failure.
     */
    public function test_run_timeout_and_schedule_deadline_at_the_same_time_produce_one_failure(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->heartbeat()->create(['heartbeat_due_at' => now('UTC')]);
        $this->signal($monitor, fake()->uuid(), 'start');
        $this->travel(5)->minutes();

        app(MonitorScheduler::class)->schedule();

        $this->assertSame('heartbeat_timeout', $monitor->checks()->sole()->reason);
        $this->assertNull($this->reload($monitor)->next_check_at);
        $this->assertDatabaseCount('incidents', 1);
    }

    /**
     * Outbox failure rolls back the run result incident and schedule atomically.
     */
    public function test_outbox_failure_rolls_back_the_run_result_incident_and_schedule_atomically(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->heartbeat()->create();
        $next = $monitor->next_check_at;
        $destination = AlertDestination::factory()->for($monitor->environment->project->account)->create();
        $monitor->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        config(['queue.connections.alerts.driver' => 'sync']);

        try {
            $this->signal($monitor, fake()->uuid(), 'failure');
            $this->fail('Expected durable outbox configuration rejection.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('database', $exception->getMessage());
        }

        foreach (['heartbeat_runs', 'monitor_checks', 'incidents', 'alert_deliveries', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertEquals($next, $this->reload($monitor)->next_check_at);
        $this->assertNull($this->reload($monitor)->heartbeat_sequence);
    }

    /**
     * One minute scheduler selects due heartbeats with a bounded batch.
     */
    public function test_one_minute_scheduler_selects_due_heartbeats_with_a_bounded_batch(): void
    {
        $this->freezeTime();
        $monitors = Monitor::factory()->heartbeat()->count(2)->create([
            'heartbeat_due_at' => now('UTC')->subHour(), 'next_check_at' => now('UTC')->subMinutes(55),
        ]);

        $scheduler = app(MonitorScheduler::class);
        $this->assertSame([1, 1, 0], [$scheduler->schedule(1), $scheduler->schedule(1), $scheduler->schedule(1)]);

        $this->assertSame(['down', 'down'], Monitor::query()->whereKey($monitors->modelKeys())->pluck('health')->all());
        $this->assertDatabaseCount('monitor_checks', 2);
        $this->assertDatabaseEmpty('jobs');
    }

    /**
     * Successful daily heartbeat does not become stale after external check intervals.
     */
    public function test_successful_daily_heartbeat_does_not_become_stale_after_external_check_intervals(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->heartbeat()->create(['heartbeat_interval_minutes' => 1440]);
        $this->signal($monitor, fake()->uuid(), 'success');
        $this->travel(20)->hours();

        $this->assertSame('Up', $this->reload($monitor)->healthLabel());

        $this->travel(5)->hours();
        $this->assertSame('Unknown', $this->reload($monitor)->healthLabel());
        app(MonitorScheduler::class)->schedule();
        $this->assertSame('Down', $this->reload($monitor)->healthLabel());
    }

    /**
     * Heartbeat incident pages and notifications render safe type specific evidence.
     */
    public function test_heartbeat_incident_pages_and_notifications_render_safe_type_specific_evidence(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->heartbeat()->create(['name' => '<script>alert(1)</script>']);
        $destination = AlertDestination::factory()->for($monitor->environment->project->account)->create();
        $monitor->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        $this->signal($monitor, fake()->uuid(), 'failure');
        $incident = $monitor->incidents()->sole();
        $delivery = AlertDelivery::query()->sole();

        $this->actingAs($this->ownerOf($monitor))->getJson(route('app.monitoring.incidents.show', [$incident->project_id, $incident->id]))
            ->assertJsonPath('incident.observation', 'Job reported failure')->assertDontSee('<script>alert(1)</script>', false)
            ->assertJson(fn (AssertableJson $json) => $json->where('incident.configuration', fn (string $text): bool => str_contains($text, 'Cron / heartbeat'))->etc());
        $html = $this->alertEmail($delivery);
        $text = $this->alertEmail($delivery);

        $this->assertStringContainsString('Job reported failure', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Heartbeat deadline:', $text);
        $this->assertStringNotContainsString('test-heartbeat-key', $html.$text);
    }

    /** @return array{run_id: string, signal: string, replayed: bool, received_at: string} */
    private function signal(Monitor $monitor, string $runId, string $signal): array
    {
        return app(RecordHeartbeat::class)->handle($monitor->id, hash('sha256', 'test-heartbeat-key'), $runId, $signal);
    }
}
