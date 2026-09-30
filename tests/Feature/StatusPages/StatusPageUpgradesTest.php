<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPages;

use App\Actions\Monitoring\SendMonthlyStatusReports;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\StatusSubscription;
use App\Notifications\StatusMonthlyReportNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class StatusPageUpgradesTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check components are grouped under headings rated by their worst component, each month has a public uptime
     * report with its incidents and downtime, and subscribers who asked get last month's report once, on the 1st.
     *
     * @return void
     */
    public function test_component_groups_and_monthly_uptime_reports(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00', 'UTC'));
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $environment = $project->environments()->firstOrFail();
        $api = Monitor::factory()->create(['environment_id' => $environment->id, 'name' => 'API', 'health' => 'down']);
        $web = Monitor::factory()->create(['environment_id' => $environment->id, 'name' => 'Website']);
        $this->actingAs($owner)->post("/projects/{$project->id}/monitoring/status-pages", [
            'name' => 'Acme status', 'slug' => 'acme', 'published' => '1', 'monthly_report' => '1',
            'monitor_ids' => [$api->id, $web->id], 'component_groups' => [$api->id => 'Backend', $web->id => ''],
        ])->assertRedirect();
        $page = StatusPage::query()->sole();
        $this->assertSame(['Backend', null], $page->components()->orderBy('position')->pluck('group_name')->all());
        $this->assertTrue($page->monthly_report);

        // API: 9 of 10 checks passed in September, and one 30-minute incident.
        foreach (range(0, 9) as $index) {
            MonitorCheck::factory()->for($api)->create(['status' => 'completed', 'outcome' => $index === 0 ? 'down' : 'up', 'scheduled_at' => CarbonImmutable::parse('2026-09-10 00:00', 'UTC')->addHours($index), 'config_revision' => $api->config_revision]);
        }
        Incident::factory()->for($api)->create(['title' => 'API down', 'opened_at' => CarbonImmutable::parse('2026-09-10 00:00', 'UTC'), 'resolved_at' => CarbonImmutable::parse('2026-09-10 00:30', 'UTC'), 'status' => 'resolved', 'active_slot' => null, 'closure_reason' => 'recovered']);

        $this->get('/status/acme')->assertOk()->assertSeeInOrder(['Backend', 'API', 'Website'])->assertSee('Monthly uptime');
        $this->get('/status/acme/uptime/2026-09')->assertOk()->assertSee('Uptime in September 2026')->assertSee('90.000%')->assertSee('API down')->assertSee('30 minutes of downtime');
        $this->get('/status/acme/uptime/2026-12')->assertNotFound();
        $this->get('/status/acme/uptime/2025-01')->assertNotFound();

        $confirmed = StatusSubscription::factory()->for($page)->create(['email' => 'fan@example.com']);
        StatusSubscription::factory()->for($page)->pending()->create(['email' => 'pending@example.com']);
        $send = app(SendMonthlyStatusReports::class);
        $this->assertSame(0, $send->handle(), 'Only on the 1st to 3rd.');
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:30', 'UTC'));
        $this->assertSame(1, $send->handle());
        $this->assertSame(0, $send->handle(), 'Once a month.');
        Notification::assertSentOnDemandTimes(StatusMonthlyReportNotification::class, 1);
        Notification::assertSentOnDemand(StatusMonthlyReportNotification::class, fn (StatusMonthlyReportNotification $notification): bool => $notification->subscription->is($confirmed) && $notification->report['month'] === '2026-09' && $notification->report['components'][0]['uptime'] === 90.0);
    }
}
