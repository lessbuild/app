<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Contracts\Monitoring\DnsResolver;
use App\Contracts\Monitoring\TcpConnector;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\MonitorScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class TcpMonitorExecutionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Scheduled tcp checks open and recover incidents with safe notifications and history.
     */
    public function test_scheduled_tcp_checks_open_and_recover_incidents_with_safe_notifications_and_history(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->tcp()->create(['trigger_checks' => 1, 'recovery_checks' => 1]);
        $destination = AlertDestination::factory()->for($monitor->environment->project->account)->create();
        $monitor->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->twice()->with('status.example.com')->andReturn(['1.1.1.1']);
        $this->mock(TcpConnector::class)->shouldReceive('connect')->twice()->andReturn(false, true);

        app(MonitorScheduler::class)->schedule();
        $firstCheck = $monitor->checks()->sole();
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'checks', '--queue' => 'checks', '--once' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 45]));
        app(MonitorCheckRunner::class)->process($firstCheck->id);

        $this->assertSame('completed', $this->reload($firstCheck)->status);
        $this->assertSame('down', $this->reload($monitor)->health);
        $incident = $monitor->incidents()->sole();
        $this->assertSame('open', $incident->status);
        $this->assertSame(5432, $incident->rule_snapshot['tcp_port']);
        $delivery = AlertDelivery::query()->sole();
        $this->assertSame('tcp_connection_failed', $delivery->payload['observation']['reason']);
        $this->assertStringNotContainsString('status.example.com', (string) json_encode($delivery->payload));
        foreach ([$this->alertEmail($delivery)] as $mail) {
            $this->assertStringContainsString('TCP monitor', $mail);
            $this->assertStringNotContainsString('HTTP —', $mail);
        }
        $this->actingAs($this->ownerOf($monitor))
            ->getJson((route('app.monitoring.incidents.show', [$incident->project_id, $incident->id])))->assertOk()->assertSee('TCP port 5432');

        $this->travel(5)->minutes();
        app(MonitorScheduler::class)->schedule();
        $lastCheck = $monitor->checks()->where('status', 'queued')->sole();
        app(MonitorCheckRunner::class)->process($lastCheck->id);

        $this->assertSame('up', $this->reload($monitor)->health);
        $this->assertSame('tcp_connected', $this->reload($lastCheck)->reason);
        $this->assertSame(['type' => 'tcp', 'port' => 5432], $this->reload($lastCheck)->details);
        $this->assertSame('recovered', $this->reload($incident)->closure_reason);
        $this->assertSame(['opened', 'recovered'], AlertDelivery::query()->orderBy('created_at')->pluck('event')->all());
    }
}
