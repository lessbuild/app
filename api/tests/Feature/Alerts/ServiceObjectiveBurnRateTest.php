<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Models\ServiceLevelObjective;
use App\Models\TelemetryEvent;
use App\Services\Monitoring\ServiceObjectiveBurnRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ServiceObjectiveBurnRateTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Paid plan surfaces a sustained fast burn signal.
     */
    public function test_paid_plan_surfaces_a_sustained_fast_burn_signal(): void
    {
        $this->travelTo('2026-09-21 12:00:00 UTC');
        $objective = ServiceLevelObjective::factory()->create(['target' => 99.9]);
        $account = $objective->environment->project->account;
        $this->onMonitoringTier($account, 'pro');
        $environment = $objective->environment;
        for ($index = 0; $index < 9; $index++) {
            TelemetryEvent::factory()->for($environment)->create(['status_code' => 200, 'occurred_at' => now()->subMinutes(30)]);
        }
        TelemetryEvent::factory()->for($environment)->create(['status_code' => 500, 'occurred_at' => now()->subMinutes(30)]);

        $analysis = app(ServiceObjectiveBurnRate::class)->forObjective($objective);

        $this->assertSame('critical', $analysis['status']);
        $this->assertSame(100.0, $analysis['short']['burn_rate']);
        $this->assertSame(100.0, $analysis['long']['burn_rate']);
        $this->actingAs($this->ownerOf($account))->getJson(route('app.monitoring.objectives.show', [$objective->environment->project_id, $objective->id]))
            ->assertOk()->assertJsonPath('burnRate.label', 'Fast burn')->assertJsonPath('burnRate.short', 100);
    }

    /**
     * Free plan gets a paid feature prompt instead of burn analysis.
     */
    public function test_free_plan_gets_a_paid_feature_prompt_instead_of_burn_analysis(): void
    {
        $objective = ServiceLevelObjective::factory()->create();
        $account = $objective->environment->project->account;

        $this->actingAs($this->ownerOf($account))->getJson(route('app.monitoring.objectives.show', [$objective->environment->project_id, $objective->id]))
            ->assertOk()->assertJsonPath('burnRate', null);
    }
}
