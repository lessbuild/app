<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Contracts\Monitoring\DnsRecordResolver;
use App\Contracts\Monitoring\DnsResolver;
use App\Contracts\Monitoring\TlsCertificateInspector;
use App\Data\Monitoring\TlsCertificateInspection;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Services\Monitoring\AlertDeliveryRunner;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\MonitorScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DnsTlsMonitorExecutionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Durable worker executes dns once and encrypts evidence without putting records in jobs.
     */
    public function test_durable_worker_executes_dns_once_and_encrypts_evidence_without_putting_records_in_jobs(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->dns()->create(['dns_record_type' => 'TXT', 'dns_expected' => ['verification=secret']]);
        $this->mock(DnsRecordResolver::class)->shouldReceive('records')->once()->andReturn([['type' => 'TXT', 'txt' => 'verification=secret']]);
        Http::preventStrayRequests();
        app(MonitorScheduler::class)->schedule();
        $check = $monitor->checks()->sole();
        $this->assertStringNotContainsString('verification=secret', DB::table('jobs')->value('payload'));

        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'checks', '--queue' => 'checks', '--once' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 45]));
        app(MonitorCheckRunner::class)->process($check->id);

        $this->assertSame('completed', $this->reload($check)->status);
        $this->assertSame('up', $this->reload($monitor)->health);
        $this->assertSame(['verification=secret'], ($this->reload($check)->evidence ?? [])['observed']);
        $this->assertStringNotContainsString('verification=secret', DB::table('monitor_checks')->value('evidence'));
        $this->assertStringNotContainsString('verification=secret', (string) json_encode($this->reload($monitor)->observation));
        $this->assertSame('dns', ($this->reload($check)->details ?? [])['type']);
        foreach (['jobs', 'incidents'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        Http::assertNothingSent();
    }

    /**
     * Dns incidents recover only after confirmed passes and notifications exclude record values.
     */
    public function test_dns_incidents_recover_only_after_confirmed_passes_and_notifications_exclude_record_values(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->dns()->create([
            'interval_minutes' => 1, 'dns_record_type' => 'TXT', 'dns_expected' => ['expected-private-value'],
        ]);
        $destination = AlertDestination::factory()->for($monitor->environment->project->account)->create();
        $monitor->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        $bad = [['type' => 'TXT', 'txt' => 'observed-private-value']];
        $good = [['type' => 'TXT', 'txt' => 'expected-private-value']];
        $this->mock(DnsRecordResolver::class)->shouldReceive('records')->times(8)->andReturn($bad, null, $bad, $bad, $good, null, $good, $good);
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->with('alerts.example.com')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://alerts.example.com/events' => Http::response('', 204)]);

        $this->check($monitor);
        $this->check($monitor);
        $this->check($monitor);
        $this->assertDatabaseEmpty('incidents');
        $this->check($monitor);
        $incident = $monitor->incidents()->sole();
        $this->assertSame('open', $incident->status);
        $this->check($monitor);
        $this->check($monitor);
        $this->check($monitor);
        $this->assertNull($this->reload($incident)->closure_reason);
        $this->check($monitor);

        $this->assertSame('recovered', $this->reload($incident)->closure_reason);
        $deliveries = AlertDelivery::query()->orderBy('created_at')->get();
        $this->assertSame(['opened', 'recovered'], $deliveries->pluck('event')->all());
        $opening = $deliveries->firstOrFail();
        $this->assertSame('dns', $opening->payload['monitor']['type']);
        $this->assertSame(1, $opening->payload['observation']['details']['missing_count']);
        $this->assertStringNotContainsString('private-value', (string) json_encode($opening->payload));
        $this->assertStringNotContainsString('private-value', (string) json_encode($this->reload($incident)->toArray()));
        foreach ([$this->alertEmail($opening)] as $content) {
            $this->assertStringContainsString('DNS monitor', $content);
            $this->assertStringNotContainsString('private-value', $content);
            $this->assertStringNotContainsString('HTTP —', $content);
        }
        app(AlertDeliveryRunner::class)->process($opening->id, 0);
        $this->assertSame('accepted', $this->reload($opening)->status->value);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://alerts.example.com/events'
            && ! str_contains($request->body(), 'private-value'));

        $this->actingAs($this->ownerOf($monitor))->getJson(route('app.monitoring.incidents.show', [$incident->project_id, $incident->id]))
            ->assertSee('DNS TXT')->assertSee('expected records')->assertDontSee('private-value');
    }

    /**
     * Tls renewal recovers the incident and retains safe expiry history.
     */
    public function test_tls_renewal_recovers_the_incident_and_retains_safe_expiry_history(): void
    {
        $this->travelTo('2030-01-01 00:00:00');
        $monitor = Monitor::factory()->tls()->create(['interval_minutes' => 1]);
        $destination = AlertDestination::factory()->for($monitor->environment->project->account)->create();
        $monitor->destinations()->attach($destination, ['opened' => true, 'recovered' => true]);
        $nearExpiry = new TlsCertificateInspection(validFrom: (int) now()->subMonth()->timestamp, validUntil: (int) now()->addDays(5)->timestamp, fingerprint: str_repeat('a', 64));
        $renewed = new TlsCertificateInspection(validFrom: (int) now()->timestamp, validUntil: (int) now()->addDays(90)->timestamp, fingerprint: str_repeat('b', 64));
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->times(5)->andReturn(['1.1.1.1']);
        $this->mock(TlsCertificateInspector::class)->shouldReceive('inspect')->times(5)->andReturn(
            new TlsCertificateInspection(error: 'tls_connection_failed'), $nearExpiry,
            new TlsCertificateInspection(error: 'tls_certificate_unavailable'), $renewed, $renewed
        );

        $this->check($monitor);
        $this->check($monitor);
        $incident = $monitor->incidents()->sole();
        $this->assertSame('tls_expiring', $incident->opening_observation['reason']);
        $this->check($monitor);
        $this->check($monitor);
        $this->assertNull($this->reload($incident)->closure_reason);
        $this->check($monitor);

        $this->assertSame('recovered', $this->reload($incident)->closure_reason);
        $this->assertSame('2030-04-01T00:00:00.000000Z', ($this->reload($monitor)->observation ?? [])['details']['valid_until']);
        $this->assertSame(str_repeat('b', 64), ($this->reload($monitor)->observation ?? [])['details']['fingerprint_sha256']);
        $this->assertSame(2, AlertDelivery::query()->count());
        $this->assertSame(14, $incident->rule_snapshot['tls_expiry_days']);
        $opening = AlertDelivery::query()->oldest('created_at')->firstOrFail();
        $mail = $this->alertEmail($opening);
        $this->assertStringContainsString('TLS monitor', $mail);
        $this->assertStringContainsString('2030-01-06', $mail);
        $this->actingAs($this->ownerOf($monitor))->getJson(route('app.monitoring.monitors.show', [$monitor->environment->project_id, $monitor->id]))
            ->assertSee('Certificate expires')->assertSee('2030-04-01')->assertDontSee('HTTP —');
        $this->getJson((route('app.monitoring.incidents.show', [$incident->project_id, $incident->id])))->assertSee('expiry threshold 14 days');
    }

    /**
     * Configuration change during dns lookup fences off late results and evidence.
     */
    public function test_configuration_change_during_dns_lookup_fences_off_late_results_and_evidence(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->dns()->create(['trigger_checks' => 1]);
        $this->actingAs($this->ownerOf($monitor));
        $this->mock(DnsRecordResolver::class)->shouldReceive('records')->once()->andReturnUsing(function () use ($monitor): array {
            $this->putJson((route('app.monitoring.monitors.update', [$monitor->environment->project_id, $monitor->id])), [
                'version' => 0, 'environment_id' => $monitor->environment_id, 'name' => $monitor->name,
                'check_type' => 'dns', 'dns_record_type' => 'A', 'dns_match' => 'exact', 'dns_expected' => '8.8.8.8',
                'timeout_seconds' => 10, 'interval_minutes' => 5, 'trigger_checks' => 1, 'recovery_checks' => 2,
                'enabled' => true, 'opened' => true, 'recovered' => true,
            ])->assertSuccessful();

            return [['type' => 'A', 'ip' => '9.9.9.9']];
        });
        app(MonitorScheduler::class)->schedule();
        $check = $monitor->checks()->sole();

        app(MonitorCheckRunner::class)->process($check->id);

        $this->assertSame('cancelled', $this->reload($check)->status);
        $this->assertNull($this->reload($check)->evidence);
        $this->assertNull($this->reload($check)->details);
        $this->assertSame('unknown', $this->reload($monitor)->health);
        $this->assertSame(1, $this->reload($monitor)->config_revision);
        foreach (['incidents', 'alert_deliveries', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /**
     * Unknown monitor types never fall back to http execution.
     */
    public function test_unknown_monitor_types_never_fall_back_to_http_execution(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create(['type' => 'unsupported']);
        $this->mock(DnsResolver::class)->shouldNotReceive('addresses');
        $this->mock(DnsRecordResolver::class)->shouldNotReceive('records');
        $this->mock(TlsCertificateInspector::class)->shouldNotReceive('inspect');
        Http::preventStrayRequests();

        $check = $this->check($monitor);

        $this->assertSame('unknown', $check->outcome);
        $this->assertSame('target_invalid', $check->reason);
        $this->assertDatabaseEmpty('incidents');
        Http::assertNothingSent();
    }

    private function check(Monitor $monitor): MonitorCheck
    {
        app(MonitorScheduler::class)->schedule();
        $check = $monitor->checks()->latest('scheduled_at')->firstOrFail();
        app(MonitorCheckRunner::class)->process($check->id);
        $this->travel($monitor->interval_minutes)->minutes();

        return $this->reload($check);
    }
}
