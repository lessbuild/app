<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Models\ServiceLevelObjective;
use App\Models\TelemetryEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ServiceObjectiveExportTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Team workspace can download a formula safe csv report.
     */
    public function test_team_workspace_can_download_a_formula_safe_csv_report(): void
    {
        $objective = ServiceLevelObjective::factory()->create(['name' => '=HYPERLINK("https://evil.test","SLO")']);
        $account = $objective->environment->project->account;
        $this->onMonitoringTier($account, 'team');
        TelemetryEvent::factory()->for($objective->environment)->create([
            'type' => 'request',
            'status_code' => 200,
            'occurred_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->ownerOf($account))->getJson(route('app.monitoring.objectives.export', [$objective->environment->project_id, $objective->id]));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = (string) $response->getContent();
        $this->assertStringContainsString('objective,indicator,environment', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('total_requests', $csv);
    }

    /**
     * Pro workspace cannot download team slo reports.
     */
    public function test_pro_workspace_cannot_download_team_slo_reports(): void
    {
        $objective = ServiceLevelObjective::factory()->create();
        $account = $objective->environment->project->account;
        $this->onMonitoringTier($account, 'pro');

        $this->actingAs($this->ownerOf($account))
            ->getJson((route('app.monitoring.objectives.export', [$objective->environment->project_id, $objective->id])))
            ->assertForbidden();
    }

    /**
     * Export cannot cross workspace boundaries.
     */
    public function test_export_cannot_cross_workspace_boundaries(): void
    {
        $local = ServiceLevelObjective::factory()->create();
        $localWorkspace = $local->environment->project->account;
        $this->onMonitoringTier($localWorkspace, 'team');
        $foreign = ServiceLevelObjective::factory()->create();
        $foreignWorkspace = $foreign->environment->project->account;
        $this->onMonitoringTier($foreignWorkspace, 'team');

        $this->actingAs($this->ownerOf($localWorkspace))
            ->getJson((route('app.monitoring.objectives.export', [$foreign->environment->project_id, $foreign->id])))
            ->assertNotFound();
    }
}
