<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Models\Environment;
use App\Models\ServiceLevelObjective;
use App\Models\TelemetryEvent;
use App\Services\Monitoring\ServiceObjectiveReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ServiceLevelObjectiveTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Availability report separates good bad and unknown records.
     */
    public function test_availability_report_separates_good_bad_and_unknown_records(): void
    {
        $objective = ServiceLevelObjective::factory()->create(['target' => 50]);
        $environment = $objective->environment;
        TelemetryEvent::factory()->for($environment)->create(['type' => 'request', 'status_code' => 200, 'occurred_at' => now()->subDay()]);
        TelemetryEvent::factory()->for($environment)->create(['type' => 'request', 'status_code' => 200, 'occurred_at' => now()->subDay()]);
        TelemetryEvent::factory()->for($environment)->create(['type' => 'request', 'status_code' => 500, 'occurred_at' => now()->subDay()]);
        TelemetryEvent::factory()->for($environment)->create(['type' => 'request', 'status_code' => null, 'occurred_at' => now()->subDay()]);

        $report = app(ServiceObjectiveReport::class)->forObjective($objective);

        $this->assertSame(4, $report['total']);
        $this->assertSame(3, $report['observed']);
        $this->assertSame(2, $report['good']);
        $this->assertSame(1, $report['bad']);
        $this->assertSame(1, $report['unknown']);
        $this->assertSame(66.667, $report['compliance']);
        $this->assertSame(33.33, $report['budget_remaining']);
        $this->assertSame('healthy', $report['status']);
    }

    /**
     * Latency report applies service and route scope.
     */
    public function test_latency_report_applies_service_and_route_scope(): void
    {
        $objective = ServiceLevelObjective::factory()->create([
            'indicator' => 'latency', 'service' => 'checkout', 'route' => '/pay', 'target' => 50,
            'latency_threshold_ms' => 100, 'status_min' => 200, 'status_max' => 399,
        ]);
        $environment = $objective->environment;
        TelemetryEvent::factory()->for($environment)->create(['type' => 'request', 'service' => 'checkout', 'route' => '/pay', 'duration_ms' => 50, 'occurred_at' => now()->subDay()]);
        TelemetryEvent::factory()->for($environment)->create(['type' => 'request', 'service' => 'checkout', 'route' => '/pay', 'duration_ms' => 150, 'occurred_at' => now()->subDay()]);
        TelemetryEvent::factory()->for($environment)->create(['type' => 'request', 'service' => 'checkout', 'route' => '/pay', 'duration_ms' => null, 'occurred_at' => now()->subDay()]);
        TelemetryEvent::factory()->for($environment)->create(['type' => 'request', 'service' => 'other', 'route' => '/pay', 'duration_ms' => 1, 'occurred_at' => now()->subDay()]);

        $report = app(ServiceObjectiveReport::class)->forObjective($objective);

        $this->assertSame(3, $report['total']);
        $this->assertSame(2, $report['observed']);
        $this->assertSame(1, $report['good']);
        $this->assertSame(1, $report['bad']);
        $this->assertSame(1, $report['unknown']);
        $this->assertSame(0.0, $report['budget_remaining']);
        $this->assertSame('warning', $report['status']);
    }

    /**
     * Owner can create and view an objective.
     */
    public function test_owner_can_create_and_view_an_objective(): void
    {
        $environment = Environment::factory()->create();
        $owner = $this->ownerOf($environment->project->account);

        $this->actingAs($owner)->postObjective([
            'name' => 'Checkout availability', 'environment_id' => $environment->id, 'indicator' => 'availability',
            'target' => '99.900', 'window_days' => 30, 'service' => 'checkout', 'route' => '/pay',
            'status_min' => 200, 'status_max' => 399, 'enabled' => 1,
        ])->assertSuccessful();

        $objective = ServiceLevelObjective::query()->firstOrFail();
        $this->actingAs($owner)->getJson(route('app.monitoring.objectives.show', [$objective->environment->project_id, $objective->id]))->assertOk()
            ->assertJsonPath('objective.name', 'Checkout availability')->assertJsonPath('objective.status', 'no_data')
            ->assertJsonPath('report.observed', 0)->assertJsonPath('canExport', false);
    }

    /**
     * Objectives are workspace scoped and can be archived.
     */
    public function test_objectives_are_workspace_scoped_and_can_be_archived(): void
    {
        $local = ServiceLevelObjective::factory()->create();
        $foreign = ServiceLevelObjective::factory()->create();
        $owner = $this->ownerOf($local->environment->project->account);

        $this->actingAs($owner)->getJson(route('app.monitoring.objectives.show', [$foreign->environment->project_id, $foreign->id]))->assertNotFound();
        $this->actingAs($owner)->deleteJson(route('app.monitoring.objectives.archive', [$local->environment->project_id, $local->id]))->assertJsonRedirect(route('monitoring.objectives', $local->environment->project_id));
        $this->assertSoftDeleted('service_level_objectives', ['id' => $local->id]);
    }
}
