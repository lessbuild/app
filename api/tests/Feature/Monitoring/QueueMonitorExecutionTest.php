<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Actions\Monitoring\RecordQueueSnapshot;
use App\Actions\Monitoring\RecordQueueWorker;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\Monitor;
use App\Models\QueueSnapshot;
use App\Models\QueueWorker;
use App\Services\Monitoring\MonitorScheduler;
use App\Support\Monitoring\QueueMonitorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class QueueMonitorExecutionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_a_silent_collector_opens_one_incident_after_grace_without_creating_network_jobs(): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = $this->monitor(['minimum_workers' => 0]);
        $destination = AlertDestination::factory()->for($monitor->environment->project->account)->create();
        $monitor->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        Http::preventStrayRequests();
        $this->travel(179)->seconds();
        $this->assertSame(0, app(MonitorScheduler::class)->schedule());
        $this->travel(1)->second();

        $this->assertSame(1, app(MonitorScheduler::class)->schedule());
        $this->assertSame(0, app(MonitorScheduler::class)->schedule());

        $this->assertSame('down', $this->reload($monitor)->health);
        $this->assertSame('queue_report_missing', $monitor->checks()->sole()->reason);
        $this->assertNull($this->reload($monitor)->next_check_at);
        $this->assertSame(['opened'], AlertDelivery::query()->pluck('event')->all());
        $this->assertSame(['alerts'], DB::table('jobs')->pluck('queue')->all());
        foreach (['queue_snapshots', 'queue_workers'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        Http::assertNothingSent();
    }

    public function test_missing_worker_heartbeats_fail_even_while_the_queue_collector_remains_fresh(): void
    {
        $this->freezeTime();
        $monitor = $this->monitor();
        QueueWorker::factory()->for($monitor)->create();
        $this->sample($monitor);
        $this->travel(120)->seconds();

        $this->sample($monitor);

        $this->assertSame('down', $this->reload($monitor)->health);
        $this->assertSame('queue_workers_missing', ($this->reload($monitor)->observation ?? [])['reason']);
        $this->assertTrue(($this->reload($monitor)->observation ?? [])['details']['fresh_snapshot']);
        $this->assertDatabaseCount('incidents', 1);
        $this->worker($monitor, fake()->uuid(), 1);
        $this->assertSame('up', $this->reload($monitor)->health);
        $this->assertSame('recovered', $monitor->incidents()->sole()->closure_reason);
    }

    #[DataProvider('thresholds')]
    public function test_each_queue_gauge_opens_and_recovers_an_incident_at_its_configured_boundary(string $metric, int $threshold): void
    {
        $this->freezeTime();
        $monitor = $this->monitor(['minimum_workers' => 0, 'max_'.$metric => $threshold]);
        $this->sample($monitor, [$metric => $threshold]);
        $this->assertSame('up', $this->reload($monitor)->health);
        $this->travel(1)->second();

        $this->sample($monitor, [$metric => $threshold + 1]);

        $this->assertSame('queue_'.$metric, ($this->reload($monitor)->observation ?? [])['reason']);
        $incident = $monitor->incidents()->sole();
        $this->assertSame('open', $incident->status);
        $this->travel(1)->second();
        $this->sample($monitor, [$metric => $threshold]);
        $this->assertSame('recovered', $this->reload($incident)->closure_reason);
    }

    /** @return array<string, list<mixed>> */
    public static function thresholds(): array
    {
        return ['ready' => ['pending', 10], 'delayed' => ['delayed', 10], 'reserved' => ['reserved', 10],
            'failed inventory' => ['failed', 0], 'oldest wait' => ['oldest_wait_seconds', 30]];
    }

    public function test_unknown_required_metrics_cannot_recover_an_open_incident(): void
    {
        $this->freezeTime();
        $monitor = $this->monitor(['minimum_workers' => 0]);
        $this->sample($monitor, ['failed' => 1]);
        $incident = $monitor->incidents()->sole();
        $this->travel(1)->second();

        $this->sample($monitor, ['failed' => null]);

        $this->assertSame('unknown', $this->reload($monitor)->health);
        $this->assertSame(['failed'], ($this->reload($monitor)->observation ?? [])['details']['missing_metrics']);
        $this->assertSame('open', $this->reload($incident)->status);
        $this->assertNull($this->reload($incident)->closure_reason);
        $this->travel(1)->second();
        $this->sample($monitor, ['failed' => 0]);
        $this->assertSame('recovered', $this->reload($incident)->closure_reason);
    }

    public function test_known_breaches_take_priority_over_other_missing_measurements(): void
    {
        $this->freezeTime();
        $monitor = $this->monitor(['minimum_workers' => 0]);

        $this->sample($monitor, ['pending' => 5000, 'failed' => null]);

        $this->assertSame('down', $this->reload($monitor)->health);
        $this->assertSame(['queue_pending'], ($this->reload($monitor)->observation ?? [])['details']['breaches']);
        $this->assertSame(['failed'], ($this->reload($monitor)->observation ?? [])['details']['missing_metrics']);
        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_disabled_thresholds_do_not_require_unsupported_metrics(): void
    {
        $this->freezeTime();
        $monitor = $this->monitor(['minimum_workers' => 0, 'max_failed' => null, 'max_oldest_wait_seconds' => null]);

        $this->sample($monitor, ['failed' => null, 'oldest_wait_seconds' => null, 'delayed' => null, 'reserved' => null]);

        $this->assertSame('up', $this->reload($monitor)->health);
        $this->assertSame([], ($this->reload($monitor)->observation ?? [])['details']['missing_metrics']);
        $this->assertDatabaseEmpty('incidents');
    }

    public function test_old_and_pre_configuration_samples_are_retained_without_overwriting_newer_health(): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = $this->monitor(['minimum_workers' => 0]);
        $this->travel(30)->seconds();
        $this->sample($monitor, ['failed' => 5]);
        $pointer = $this->reload($monitor)->queue_snapshot_id;

        $receipt = $this->sample($monitor, ['observed_at' => '2026-09-21T10:00:10Z']);
        $earlier = $this->sample($monitor, ['observed_at' => '2026-09-21T09:59:00Z']);

        $this->assertFalse($receipt['applied']);
        $this->assertFalse($earlier['applied']);
        $this->assertSame($pointer, $this->reload($monitor)->queue_snapshot_id);
        $this->assertSame('down', $this->reload($monitor)->health);
        $this->assertDatabaseCount('queue_snapshots', 3);
        $this->assertDatabaseCount('monitor_checks', 1);
        $this->assertSame('open', $monitor->incidents()->sole()->status);
    }

    public function test_a_delayed_newest_sample_uses_sample_age_and_does_not_clear_missing_data(): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = $this->monitor(['minimum_workers' => 0]);
        $this->travel(5)->minutes();

        $this->sample($monitor, ['observed_at' => '2026-09-21T10:01:00Z']);

        $this->assertSame('down', $this->reload($monitor)->health);
        $this->assertSame('queue_report_missing', ($this->reload($monitor)->observation ?? [])['reason']);
        $this->assertSame('2026-09-21 10:04:00', QueueSnapshot::query()->sole()->valid_until->format('Y-m-d H:i:s'));
        $this->assertSame('open', $monitor->incidents()->sole()->status);
    }

    public function test_future_clock_skew_cannot_extend_report_freshness_beyond_receipt_timeout(): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = $this->monitor(['minimum_workers' => 0]);

        $this->sample($monitor, ['observed_at' => '2026-09-21T10:00:30Z']);

        $this->assertSame('2026-09-21 10:03:00', QueueSnapshot::query()->sole()->valid_until->format('Y-m-d H:i:s'));
    }

    public function test_a_late_current_sample_records_the_expired_deadline_before_recovery_atomically(): void
    {
        $this->freezeTime();
        $monitor = $this->monitor(['minimum_workers' => 0]);
        $destination = AlertDestination::factory()->for($monitor->environment->project->account)->create();
        $monitor->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        $this->travel(4)->minutes();

        $this->sample($monitor);

        $this->assertSame('up', $this->reload($monitor)->health);
        $this->assertSame('recovered', $monitor->incidents()->sole()->closure_reason);
        $this->assertSame(['queue_report_missing', 'queue_healthy'], $monitor->checks()->orderBy('id')->pluck('reason')->all());
        $this->assertSame(['opened', 'recovered'], AlertDelivery::query()->orderBy('id')->pluck('event')->all());
    }

    public function test_live_busy_heartbeats_cannot_hide_a_long_running_job_and_a_new_attempt_gets_a_new_timer(): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = $this->monitor(['max_runtime_seconds' => 30]);
        $workerId = fake()->uuid();
        $jobId = fake()->uuid();
        $this->worker($monitor, $workerId, 1, 'busy', $jobId);
        $this->sample($monitor);
        $this->travel(30)->seconds();
        $this->worker($monitor, $workerId, 2, 'busy', $jobId);
        $this->assertSame('up', $this->reload($monitor)->health);
        $this->travel(1)->second();

        app(MonitorScheduler::class)->schedule();

        $this->assertSame('queue_runtime', ($this->reload($monitor)->observation ?? [])['reason']);
        $this->assertSame('2026-09-21 10:00:00', QueueWorker::query()->sole()->job_started_at?->format('Y-m-d H:i:s'));
        $this->worker($monitor, $workerId, 3, 'busy', fake()->uuid());
        $this->assertSame('up', $this->reload($monitor)->health);
        $this->assertSame('2026-09-21 10:00:31', QueueWorker::query()->sole()->job_started_at->format('Y-m-d H:i:s'));
        $this->assertSame('recovered', $monitor->incidents()->sole()->closure_reason);
    }

    public function test_stopped_workers_and_replayed_heartbeats_do_not_satisfy_capacity(): void
    {
        $this->freezeTime();
        $monitor = $this->monitor(['worker_timeout_seconds' => 30]);
        $id = fake()->uuid();
        $this->worker($monitor, $id, 1);
        $this->sample($monitor);
        $this->travel(30)->seconds();

        $this->assertTrue($this->worker($monitor, $id, 1)['replayed']);
        app(MonitorScheduler::class)->schedule();

        $this->assertSame('down', $this->reload($monitor)->health);
        $this->worker($monitor, $id, 2);
        $this->assertSame('up', $this->reload($monitor)->health);
        $this->worker($monitor, $id, 3, 'stopped');
        $this->assertSame('down', $this->reload($monitor)->health);
        $this->assertNull(QueueWorker::query()->sole()->job_id);
    }

    public function test_outbox_failure_rolls_back_snapshot_health_incident_and_schedule_together(): void
    {
        $this->freezeTime();
        $monitor = $this->monitor(['minimum_workers' => 0]);
        $deadline = $monitor->next_check_at;
        $destination = AlertDestination::factory()->for($monitor->environment->project->account)->create();
        $monitor->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        config(['queue.connections.alerts.driver' => 'sync']);

        try {
            $this->sample($monitor, ['failed' => 10]);
            $this->fail('Expected a durable outbox configuration rejection.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('database', $exception->getMessage());
        }

        foreach (['queue_snapshots', 'monitor_checks', 'incidents', 'alert_deliveries', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertSame('unknown', $this->reload($monitor)->health);
        $this->assertNull($this->reload($monitor)->queue_snapshot_id);
        $this->assertEquals($deadline, $this->reload($monitor)->next_check_at);
    }

    public function test_scheduler_handles_due_queues_in_bounded_batches(): void
    {
        $this->freezeTime();
        $first = $this->monitor(['minimum_workers' => 0]);
        $second = $this->monitor(['minimum_workers' => 0]);
        $this->travel(4)->minutes();

        $scheduler = app(MonitorScheduler::class);
        $this->assertSame([1, 1, 0], [$scheduler->schedule(1), $scheduler->schedule(1), $scheduler->schedule(1)]);

        $this->assertSame('down', $this->reload($first)->health);
        $this->assertSame('down', $this->reload($second)->health);
        $this->assertDatabaseCount('monitor_checks', 2);
        $this->assertDatabaseEmpty('jobs');
    }

    public function test_queue_health_stays_current_until_its_signal_deadline_and_reports_a_stalled_evaluator(): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = $this->monitor(['minimum_workers' => 0, 'report_timeout_seconds' => 3600]);
        $this->sample($monitor);
        $this->travel(59)->minutes();

        $this->assertSame('Up', $this->reload($monitor)->healthLabel());

        $this->travel(4)->minutes();
        $this->assertSame('Unknown', $this->reload($monitor)->healthLabel());
        $this->assertSame(1, app(MonitorScheduler::class)->schedule());
        $this->assertSame('Down', $this->reload($monitor)->healthLabel());
        $this->assertSame('queue_report_missing', ($this->reload($monitor)->observation ?? [])['reason']);
    }

    /** @param array<string, int|null> $settings */
    private function monitor(array $settings = []): Monitor
    {
        $settings = array_replace(QueueMonitorSettings::DEFAULTS, $settings);

        return Monitor::factory()->queueMonitor()->create(['queue_settings' => $settings,
            'next_check_at' => now('UTC')->addSeconds((int) ($settings['minimum_workers'] > 0
                ? min($settings['report_timeout_seconds'], $settings['worker_timeout_seconds']) : $settings['report_timeout_seconds']))]);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function sample(Monitor $monitor, array $values = []): array
    {
        return app(RecordQueueSnapshot::class)->handle($monitor->id, hash('sha256', 'test-queue-monitor-key'), array_replace([
            'snapshot_id' => fake()->uuid(), 'observed_at' => now('UTC')->toISOString(), 'pending' => 5,
            'delayed' => 0, 'reserved' => 0, 'failed' => 0, 'oldest_wait_seconds' => 10,
        ], $values));
    }

    /** @return array<string, mixed> */
    private function worker(Monitor $monitor, string $id, int $sequence, string $status = 'idle', ?string $jobId = null): array
    {
        return app(RecordQueueWorker::class)->handle($monitor->id, hash('sha256', 'test-queue-monitor-key'),
            ['worker_id' => $id, 'sequence' => $sequence, 'status' => $status, 'job_id' => $jobId]);
    }
}
