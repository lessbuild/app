<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Models\AlertRule;
use App\Models\Environment;
use App\Models\TelemetryEvent;
use App\Services\Monitoring\AlertRuleEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class LogPatternAlertTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_paid_log_pattern_rule_opens_an_incident_for_matching_events(): void
    {
        $this->travelTo('2026-09-21 12:00:00 UTC');
        $environment = Environment::factory()->create();
        for ($index = 0; $index < 2; $index++) {
            TelemetryEvent::factory()->for($environment)->create([
                'type' => 'log', 'name' => 'Payment provider timeout', 'service' => 'payments', 'occurred_at' => now()->subMinutes(2),
            ]);
        }
        TelemetryEvent::factory()->for($environment)->create([
            'type' => 'log', 'name' => 'Payment completed', 'service' => 'payments', 'occurred_at' => now()->subMinutes(2),
        ]);
        $account = $environment->project->account;
        $this->onMonitoringTier($account, 'pro');
        $rule = AlertRule::factory()->for($environment)->ready()->create([
            'metric' => 'log_pattern_count', 'match_text' => 'provider timeout', 'service' => 'payments',
            'threshold' => 2, 'window_minutes' => 5, 'minimum_samples' => 1, 'trigger_checks' => 1, 'recovery_checks' => 1,
        ]);

        app(AlertRuleEvaluator::class)->evaluate();

        $rule->refresh();
        $this->assertSame('breaching', $rule->evaluation_state);
        $this->assertSame(2.0, (float) $this->observed($rule, 'value'));
        $incident = $rule->incidents()->sole();
        $this->assertSame('log_pattern_count', $incident->rule_snapshot['metric']);
        $this->assertSame('provider timeout', $incident->rule_snapshot['match_text']);
    }

    public function test_free_plan_cannot_create_a_log_pattern_alert_rule(): void
    {
        $environment = Environment::factory()->create();

        $this->actingAs($this->ownerOf($environment->project->account))->postRule([
            'name' => 'Payment provider failures', 'environment_id' => $environment->id, 'metric' => 'log_pattern_count',
            'match_text' => 'provider timeout', 'threshold' => 1, 'window_minutes' => 5,
            'minimum_samples' => 1, 'trigger_checks' => 1, 'recovery_checks' => 1, 'enabled' => 1,
        ])->assertSessionHasErrors(['metric' => 'Log pattern alerts come with Monitoring Pro and above.']);

        $this->assertDatabaseEmpty('alert_rules');
    }

    public function test_paid_plan_persists_a_service_scoped_log_pattern(): void
    {
        $environment = Environment::factory()->create();
        $account = $environment->project->account;
        $this->onMonitoringTier($account, 'pro');

        $this->actingAs($this->ownerOf($account))->postRule([
            'name' => 'Payment provider failures', 'environment_id' => $environment->id, 'metric' => 'log_pattern_count',
            'match_text' => 'provider timeout', 'service' => 'payments', 'threshold' => 1, 'window_minutes' => 5,
            'minimum_samples' => 1, 'trigger_checks' => 1, 'recovery_checks' => 1, 'enabled' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('alert_rules', [
            'metric' => 'log_pattern_count', 'match_text' => 'provider timeout', 'service' => 'payments',
        ]);
    }
}
