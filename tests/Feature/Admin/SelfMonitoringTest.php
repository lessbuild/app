<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Monitoring\RecordHeartbeat;
use App\Contracts\Telemetry\TelemetryIngestor;
use App\Jobs\Admin\ReportPlatformException;
use App\Models\AlertDestination;
use App\Models\HeartbeatRun;
use App\Models\IngestToken;
use App\Models\Monitor;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Services\Admin\SelfMonitoring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class SelfMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_platform_sets_up_monitoring_of_itself_once(): void
    {
        $this->assertSame(1, Artisan::call('platform:self-monitor'));
        $admin = User::factory()->create(['email' => 'ops@example.com', 'is_platform_admin' => true]);

        $this->assertSame(0, Artisan::call('platform:self-monitor'));
        $settings = app(SelfMonitoring::class)->settings();
        $this->assertNotNull($settings);
        [$uptime, $heartbeat] = [Monitor::query()->where('type', 'http')->sole(), Monitor::query()->where('type', 'heartbeat')->sole()];
        $this->assertStringEndsWith('/up', (string) $uptime->request_url);
        $this->assertNotNull($heartbeat->heartbeat_token_hash);
        $this->assertSame($admin->id, AlertDestination::query()->sole()->recipient_user_id);
        $this->assertSame([1, 1], [$uptime->destinations()->count(), $heartbeat->destinations()->count()]);
        $this->assertSame(1, IngestToken::query()->count());
        $this->assertTrue(app(SelfMonitoring::class)->account()?->members()->whereKey($admin->id)->exists());

        $this->assertSame(0, Artisan::call('platform:self-monitor'));
        $this->assertSame(2, Monitor::query()->count());
    }

    public function test_the_scheduler_beats_and_exceptions_are_reported_once_a_minute(): void
    {
        $monitoring = app(SelfMonitoring::class);
        $this->assertFalse($monitoring->beat(app(RecordHeartbeat::class)));
        Queue::fake([ReportPlatformException::class]);
        $monitoring->report(new RuntimeException('Before set-up'));
        Queue::assertNothingPushed();

        $monitoring->setUp(User::factory()->create(['is_platform_admin' => true]));
        $this->assertTrue($monitoring->beat(app(RecordHeartbeat::class)));
        $this->assertSame(1, HeartbeatRun::query()->count());

        $failure = new RuntimeException('Payment gateway timed out');
        $monitoring->report($failure);
        $monitoring->report($failure);
        Queue::assertPushed(ReportPlatformException::class, 1);
        Queue::assertPushedOn('telemetry', ReportPlatformException::class);

        $job = null;
        Queue::assertPushed(ReportPlatformException::class, function (ReportPlatformException $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });
        $this->assertInstanceOf(ReportPlatformException::class, $job);
        $job->handle($monitoring, app(TelemetryIngestor::class));
        $event = TelemetryEvent::query()->sole();
        $this->assertSame([$monitoring->settings()['environment_id'] ?? null, 'exception'], [$event->environment_id, $event->type]);
    }
}
