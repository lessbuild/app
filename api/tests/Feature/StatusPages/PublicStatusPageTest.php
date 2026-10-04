<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPages;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\StatusPage;
use App\Models\StatusUpdate;
use App\Services\Monitoring\UptimeHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicStatusPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_shows_component_health_and_open_monitor_incidents(): void
    {
        [$page, $monitor] = $this->page(['health' => 'down', 'checked_at' => now()]);
        Incident::factory()->for($monitor)->create(['title' => 'Payments API is down']);

        $this->getJson('/api/app/status/acme')->assertOk()->assertJsonPath('page.url', route('status.show', 'acme'))->assertJsonPath('overall', 'major_outage')
            ->assertJsonHasText('Major outage')->assertJsonHasText('Payments API is down')->assertJsonHasText('Payments')->assertJsonLacksText('private.internal.example');

        $page->forceFill(['published' => false])->save();
        $this->getJson('/api/app/status/acme')->assertNotFound();
        $this->get('/status/acme/report.json')->assertNotFound();
        $this->getJson('/api/app/status/unknown')->assertNotFound();
    }

    public function test_healthy_components_and_archived_monitors(): void
    {
        [, $monitor] = $this->page(['health' => 'up', 'checked_at' => now()]);

        $this->getJson('/api/app/status/acme')->assertOk()->assertJsonHasText('All systems operational');

        $monitor->delete();
        $this->getJson('/api/app/status/acme')->assertOk()->assertJsonCount(0, 'components');
    }

    public function test_team_updates_set_the_overall_state_and_list_maintenance_and_history(): void
    {
        [$page] = $this->page(['health' => 'up', 'checked_at' => now()]);
        $incident = StatusUpdate::factory()->for($page)->create(['title' => 'Slow checkout', 'severity' => 'major']);
        StatusUpdate::factory()->for($page)->maintenance()->create(['title' => 'Database upgrade']);
        StatusUpdate::factory()->for($page)->resolved()->create(['title' => 'Login errors', 'root_cause' => 'An expired certificate.']);
        StatusUpdate::factory()->for($page)->resolved()->create(['title' => 'Ancient history', 'resolved_at' => now()->subDays(40)]);

        $this->getJson('/api/app/status/acme')->assertOk()->assertJsonHasText('Degraded performance')->assertJsonHasText('Slow checkout')
            ->assertJsonPath('upcomingMaintenance.0.title', 'Database upgrade')
            ->assertJsonFragment(['title' => 'Login errors'])->assertJsonHasText('An expired certificate.')->assertJsonLacksText('Ancient history');

        $incident->forceFill(['severity' => 'critical'])->save();
        $this->getJson('/api/app/status/acme')->assertJsonHasText('Major outage');
        $incident->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
        StatusUpdate::factory()->for($page)->maintenance()->create(['status' => 'in_progress', 'starts_at' => now()->subMinutes(5)]);
        $this->getJson('/api/app/status/acme')->assertJsonHasText('Under maintenance');
    }

    public function test_recent_resolved_monitor_incidents_are_listed_but_old_ones_are_not(): void
    {
        $this->freezeTime();
        [, $monitor] = $this->page();
        Incident::factory()->for($monitor)->resolved()->create(['title' => 'Payments API recovered', 'opened_at' => now()->subHours(3), 'resolved_at' => now()->subHours(2)]);
        Incident::factory()->for($monitor)->resolved()->create(['title' => 'Old incident', 'opened_at' => now()->subDays(32), 'resolved_at' => now()->subDays(31)]);

        $this->getJson('/api/app/status/acme')->assertOk()->assertJsonHasText('Payments API recovered')->assertJsonLacksText('Old incident');
    }

    public function test_history_counts_only_the_current_configuration_and_leaves_unknown_checks_out_of_uptime(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 14:00:00', 'UTC'));
        [, $monitor] = $this->page(['config_revision' => 3]);
        $now = CarbonImmutable::now('UTC');
        foreach ([
            [$now->subDays(31), 'down', 3], [$now->subDay(), 'up', 3], [$now->setTime(10, 0), 'up', 3],
            [$now->setTime(11, 0), 'down', 3], [$now->setTime(12, 0), 'unknown', 3], [$now->setTime(13, 0), 'down', 2],
        ] as [$at, $outcome, $revision]) {
            MonitorCheck::factory()->for($monitor)->create(['status' => 'completed', 'scheduled_at' => $at, 'outcome' => $outcome, 'config_revision' => $revision]);
        }

        $history = app(UptimeHistory::class)->forMonitors([$monitor], $now)[$monitor->id];

        $this->assertSame([2, 1, 1, 3, 66.67], [$history['passed'], $history['failed'], $history['unknown'], $history['measured'], $history['uptime']]);
        $this->assertCount(30, $history['days']);
        $this->assertSame('outage', $history['days'][29]['state']);
        $this->assertSame('operational', $history['days'][28]['state']);
        $this->assertSame('no_data', $history['days'][0]['state']);
        $this->getJson('/api/app/status/acme')->assertJsonPath('components.0.name', 'Payments')->assertJsonPath('components.0.history.uptime', fn ($uptime): bool => abs((float) $uptime - 66.67) < 0.01);
    }

    public function test_the_json_report_keeps_deployers_shape(): void
    {
        [$page] = $this->page(['health' => 'up', 'checked_at' => now()]);
        StatusUpdate::factory()->for($page)->create(['title' => 'Slow checkout']);

        $this->getJson('/status/acme/report.json')->assertOk()->assertHeader('Cache-Control', 'max-age=30, public')
            ->assertJsonPath('name', 'Acme status')
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('state', 'degraded')
            ->assertJsonPath('components.0.name', 'Payments')
            ->assertJsonPath('components.0.operational', true)
            ->assertJsonPath('components.0.status', 'Operational')
            ->assertJsonPath('incidents.0.title', 'Slow checkout')
            ->assertJsonPath('incidents.0.kind', 'incident')
            ->assertJsonStructure(['updated_at', 'components' => [['uptime_30d', 'checked_at']], 'incidents' => [['status', 'severity', 'message', 'starts_at', 'ends_at', 'resolved_at']]]);
    }

    public function test_the_unified_apps_addresses_redirect(): void
    {
        $this->page();

        $this->get('/status/monitor/acme')->assertRedirect('/status/acme')->assertStatus(301);
        $this->get('/status/deployer/acme')->assertRedirect('/status/acme');
        $this->get('/status/other/acme')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $monitor
     * @return array{StatusPage, Monitor}
     */
    private function page(array $monitor = []): array
    {
        $record = Monitor::factory()->create(['name' => 'Internal name', 'request_url' => 'https://private.internal.example/health', ...$monitor]);
        $page = StatusPage::factory()->create(['account_id' => $record->environment->project->account_id, 'name' => 'Acme status', 'slug' => 'acme']);
        $page->components()->create(['monitor_id' => $record->id, 'label' => 'Payments', 'position' => 0]);

        return [$page, $record];
    }
}
