<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Contracts\Monitoring\DnsResolver;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Services\Monitoring\AlertDeliveryRunner;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\MonitorScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MonitorExecutionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_scheduler_creates_one_atomic_durable_job_without_secrets_or_duplicate_slots(): void
    {
        $this->travelTo('2026-09-21 10:00:00 UTC');
        $monitor = Monitor::factory()->create(['request_url' => 'https://status.example.com/private-path?token=hidden', 'bearer_token' => 'secret-credential']);
        $scheduler = app(MonitorScheduler::class);

        $this->assertSame(1, $scheduler->schedule());
        $this->assertSame(0, $scheduler->schedule());

        $check = MonitorCheck::query()->sole();
        $this->assertSame($monitor->id, $check->monitor_id);
        $this->assertSame('queued', $check->status);
        $this->assertSame('2026-09-21 10:05:00', $this->reload($monitor)->next_check_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('jobs', 1);
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringContainsString($check->id, $payload);
        $this->assertStringNotContainsString('private-path', $payload);
        $this->assertStringNotContainsString('secret-credential', $payload);
    }

    public function test_database_worker_processes_a_check_once_and_duplicate_dispatch_is_harmless(): void
    {
        $monitor = Monitor::factory()->create();
        $this->fakeEndpoint(200);
        app(MonitorScheduler::class)->schedule();
        $check = $monitor->checks()->sole();

        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'checks', '--queue' => 'checks', '--once' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 45]));
        app(MonitorCheckRunner::class)->process($check->id);

        $this->assertSame('completed', $this->reload($check)->status);
        $this->assertSame('up', $this->reload($monitor)->health);
        $this->assertDatabaseEmpty('jobs');
        $this->assertDatabaseEmpty('incidents');
        Http::assertSentCount(1);
    }

    public function test_consecutive_failures_open_one_incident_and_confirmed_passes_recover_with_routed_notifications(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create(['interval_minutes' => 1]);
        $workspace = $monitor->environment->project->account;
        $destination = AlertDestination::factory()->for($workspace)->create();
        $monitor->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://status.example.com/health' => Http::sequence()->push('', 503)->push('', 503)->push('', 503)->push('', 200)->push('', 200)]);

        $this->check($monitor);
        $this->assertDatabaseEmpty('incidents');
        $this->check($monitor);
        $incident = $monitor->incidents()->sole();
        $this->assertSame('open', $incident->status);
        $this->assertSame(['opened'], AlertDelivery::query()->pluck('event')->all());
        $this->check($monitor);
        $this->check($monitor);
        $this->assertSame('open', $this->reload($incident)->status);
        $this->check($monitor);

        $this->assertSame('recovered', $this->reload($incident)->closure_reason);
        $this->assertNull($this->reload($incident)->active_slot);
        $this->assertSame(['monitor_failed', 'recovered'], $incident->activities()->orderBy('id')->pluck('action')->all());
        $this->assertSame(['opened', 'recovered'], AlertDelivery::query()->orderBy('created_at')->pluck('event')->all());
        $payload = AlertDelivery::query()->oldest('created_at')->firstOrFail()->payload;
        $this->assertNull($payload['rule']);
        $this->assertSame('Public API health', $payload['monitor']['name']);
        $this->assertStringNotContainsString('status.example.com', (string) json_encode($payload));
        Http::assertSentCount(5);
    }

    public function test_monitor_open_notification_is_delivered_through_the_existing_outbox(): void
    {
        $monitor = Monitor::factory()->create(['trigger_checks' => 1]);
        $destination = AlertDestination::factory()->for($monitor->environment->project->account)->create();
        $monitor->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake([
            'https://status.example.com/health' => Http::response('', 503),
            'https://alerts.example.com/events' => Http::response('', 204),
        ]);
        $this->check($monitor);
        $delivery = AlertDelivery::query()->sole();

        app(AlertDeliveryRunner::class)->process($delivery->id, 0);

        $this->assertSame('accepted', $this->reload($delivery)->status->value);
        Http::assertSentCount(2);
    }

    public function test_unknown_results_reset_streaks_and_never_resolve_an_open_incident(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create(['interval_minutes' => 1]);
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(
            ['1.1.1.1'], [], ['1.1.1.1'], ['1.1.1.1'], [], ['1.1.1.1'], [], ['1.1.1.1'], ['1.1.1.1'],
        );
        Http::preventStrayRequests();
        Http::fake(['https://status.example.com/health' => Http::sequence()->push('', 503)->push('', 503)->push('', 503)->push('', 200)->push('', 200)->push('', 200)]);

        $this->check($monitor);
        $this->check($monitor);
        $this->check($monitor);
        $this->assertDatabaseEmpty('incidents');
        $this->check($monitor);
        $incident = $monitor->incidents()->sole();
        $this->check($monitor);
        $this->check($monitor);
        $this->check($monitor);
        $this->check($monitor);

        $this->assertSame('open', $this->reload($incident)->status);
        $this->assertSame(1, $this->reload($monitor)->recovery_streak);
        $this->check($monitor);
        $this->assertSame('recovered', $this->reload($incident)->closure_reason);
        Http::assertSentCount(6);
    }

    #[DataProvider('ineligibleSources')]
    public function test_paused_archived_or_changed_sources_cancel_queued_checks_without_network_io(string $change): void
    {
        $monitor = Monitor::factory()->create();
        $check = MonitorCheck::factory()->for($monitor)->create();
        match ($change) {
            'paused monitor' => $monitor->forceFill(['enabled' => false])->save(),
            'changed monitor' => $monitor->forceFill(['config_revision' => 1])->save(),
            'archived monitor' => $monitor->delete(),
            'monitoring turned off' => $monitor->environment->project->enabledServices()->where('service', 'monitoring')->delete(),
            default => throw new LogicException($change),
        };
        Http::preventStrayRequests();

        app(MonitorCheckRunner::class)->process($check->id);

        $this->assertSame('cancelled', $this->reload($check)->status);
        $this->assertSame('source_changed', $this->reload($check)->reason);
        $this->assertDatabaseEmpty('incidents');
        Http::assertNothingSent();
    }

    /** @return array<string, list<mixed>> */
    public static function ineligibleSources(): array
    {
        $changes = ['paused monitor', 'changed monitor', 'archived monitor', 'monitoring turned off'];

        return array_combine($changes, array_map(fn (string $change): array => [$change], $changes));
    }

    public function test_configuration_change_during_network_io_fences_the_late_result(): void
    {
        $monitor = Monitor::factory()->create(['trigger_checks' => 1]);
        $check = MonitorCheck::factory()->for($monitor)->create();
        $level = DB::transactionLevel();
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://status.example.com/health' => function () use ($monitor, $level) {
            $this->assertSame($level, DB::transactionLevel());
            $monitor->forceFill(['config_revision' => 1])->save();

            return Http::response('', 503);
        }]);

        app(MonitorCheckRunner::class)->process($check->id);

        $this->assertSame('cancelled', $this->reload($check)->status);
        $this->assertSame('unknown', $this->reload($monitor)->health);
        $this->assertDatabaseEmpty('incidents');
        Http::assertSentCount(1);
    }

    public function test_expired_pending_check_becomes_unknown_and_its_unreserved_job_is_discarded(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create();
        app(MonitorScheduler::class)->schedule();
        $check = $monitor->checks()->sole();
        $this->travel(121)->seconds();
        Http::preventStrayRequests();

        $this->assertSame(1, app(MonitorScheduler::class)->recover());

        $this->assertSame('unknown', $this->reload($monitor)->health);
        $this->assertSame('check_missed', $this->reload($check)->reason);
        $this->assertDatabaseEmpty('jobs');
        $this->assertDatabaseEmpty('incidents');
        Http::assertNothingSent();
    }

    public function test_running_check_is_not_stolen_until_its_lease_expires(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create(['failure_streak' => 1]);
        $check = MonitorCheck::factory()->for($monitor)->create(['status' => 'running', 'processing_token' => fake()->uuid()]);
        Http::preventStrayRequests();

        app(MonitorCheckRunner::class)->process($check->id);
        $this->assertSame(0, app(MonitorScheduler::class)->recover());
        $this->travel(121)->seconds();
        app(MonitorScheduler::class)->recover();

        $this->assertSame('worker_interrupted', $this->reload($check)->reason);
        $this->assertNull($this->reload($check)->processing_token);
        $this->assertSame(0, $this->reload($monitor)->failure_streak);
        Http::assertNothingSent();
    }

    public function test_scheduler_does_not_backfill_an_outage_and_reports_skipped_intervals(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create(['interval_minutes' => 5, 'next_check_at' => now()->subMinutes(21), 'failure_streak' => 1]);

        app(MonitorScheduler::class)->schedule();

        $this->assertSame(4, $monitor->checks()->sole()->skipped_intervals);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_scheduler_limit_does_not_starve_due_monitors_behind_a_pending_check(): void
    {
        $this->freezeTime();
        $pending = Monitor::factory()->create(['next_check_at' => now()->subHour()]);
        MonitorCheck::factory()->for($pending)->create();
        $due = Monitor::factory()->create();

        $this->assertSame(1, app(MonitorScheduler::class)->schedule(1));

        $this->assertSame(1, $due->checks()->count());
        $this->assertSame(1, $pending->checks()->count());
    }

    public function test_queue_dispatch_failure_rolls_back_check_and_schedule_state(): void
    {
        $monitor = Monitor::factory()->create();
        $before = $monitor->next_check_at;
        config(['queue.connections.checks.driver' => 'sync']);

        try {
            app(MonitorScheduler::class)->schedule();
            $this->fail('Expected durable-queue configuration rejection.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('primary database queue', $exception->getMessage());
        }

        $this->assertDatabaseEmpty('monitor_checks');
        $this->assertDatabaseEmpty('jobs');
        $this->assertEquals($before, $this->reload($monitor)->next_check_at);
    }

    public function test_command_rejects_unbounded_work_limits(): void
    {
        $this->assertSame(2, Artisan::call('monitors:check', ['--limit' => 1001]));
        $this->assertDatabaseEmpty('monitor_checks');
    }

    public function test_subsecond_scheduler_jitter_does_not_delay_the_next_minute_check(): void
    {
        $this->travelTo('2026-09-21 10:00:00.500000 UTC');
        $monitor = Monitor::factory()->create(['interval_minutes' => 1]);
        $this->fakeEndpoint(200);
        app(MonitorScheduler::class)->schedule();
        app(MonitorCheckRunner::class)->process($monitor->checks()->sole()->id);
        $this->travelTo('2026-09-21 10:01:00.100000 UTC');

        $this->assertSame(1, app(MonitorScheduler::class)->schedule());

        $this->assertSame(2, $monitor->checks()->count());
        $this->assertSame('2026-09-21 10:02:00', $this->reload($monitor)->next_check_at?->format('Y-m-d H:i:s'));
        Http::assertSentCount(1);
    }

    private function fakeEndpoint(int $status): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://status.example.com/health' => Http::response('', $status)]);
    }

    private function check(Monitor $monitor): MonitorCheck
    {
        $check = MonitorCheck::factory()->for($monitor)->create(['config_revision' => $this->reload($monitor)->config_revision]);
        app(MonitorCheckRunner::class)->process($check->id);
        $this->travel(1)->minutes();

        return $this->reload($check);
    }
}
