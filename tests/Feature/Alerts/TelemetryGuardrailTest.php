<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Models\AlertRule;
use App\Models\Environment;
use App\Models\Incident;
use App\Models\TelemetryEvent;
use App\Services\Monitoring\AlertRuleEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class TelemetryGuardrailTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_low_volume_rule_breaches_an_empty_window_and_recovers_after_events_return(): void
    {
        $this->travelTo('2026-09-21 12:00:00 UTC');
        $rule = AlertRule::factory()->ready()->create([
            'metric' => 'telemetry_volume', 'threshold' => 0, 'window_minutes' => 1,
            'minimum_samples' => 1, 'trigger_checks' => 1, 'recovery_checks' => 1,
        ]);

        app(AlertRuleEvaluator::class)->evaluate();

        $this->assertSame('breaching', $this->reload($rule)->evaluation_state);
        $this->assertSame(0.0, (float) $this->observed($rule, 'value'));
        $this->assertSame(0, $this->observed($rule, 'samples'));
        $incident = Incident::query()->sole();

        $this->travel(1)->minutes();
        TelemetryEvent::factory()->for($rule->environment)->create(['occurred_at' => now()->subSeconds(90)]);
        app(AlertRuleEvaluator::class)->evaluate();

        $this->assertSame('healthy', $this->reload($rule)->evaluation_state);
        $this->assertSame('resolved', $this->reload($incident)->status);
        $this->assertSame('recovered', $this->reload($incident)->closure_reason);
    }

    public function test_freshness_rule_breaches_when_the_latest_event_gets_too_old(): void
    {
        $this->travelTo('2026-09-21 12:00:00 UTC');
        $rule = AlertRule::factory()->ready()->create([
            'metric' => 'telemetry_freshness', 'threshold' => 300, 'window_minutes' => 5,
            'minimum_samples' => 1, 'trigger_checks' => 1, 'recovery_checks' => 1,
        ]);
        TelemetryEvent::factory()->for($rule->environment)->create(['occurred_at' => now()->subMinutes(4)]);

        app(AlertRuleEvaluator::class)->evaluate();
        $this->assertSame('healthy', $this->reload($rule)->evaluation_state);

        $this->travel(2)->minutes();
        app(AlertRuleEvaluator::class)->evaluate();

        $this->assertSame('breaching', $this->reload($rule)->evaluation_state);
        $this->assertSame(300.0, (float) $this->observed($rule, 'value'));
        $this->assertDatabaseCount('incidents', 1);

        $this->travel(1)->minutes();
        TelemetryEvent::factory()->for($rule->environment)->create(['occurred_at' => now()->subSeconds(90)]);
        app(AlertRuleEvaluator::class)->evaluate();

        $this->assertSame('resolved', Incident::query()->sole()->status);
    }

    public function test_free_plan_cannot_create_telemetry_guardrail_rules(): void
    {
        $environment = Environment::factory()->create();
        $owner = $this->ownerOf($environment->project->account);

        $this->actingAs($owner)->postRule([
            'name' => 'Telemetry heartbeat', 'environment_id' => $environment->id, 'metric' => 'telemetry_freshness',
            'threshold' => 300, 'window_minutes' => 5, 'minimum_samples' => 1,
            'trigger_checks' => 1, 'recovery_checks' => 1, 'enabled' => 1,
        ])->assertSessionHasErrors(['metric' => 'Telemetry volume and freshness alerts come with Monitoring Pro and above.']);

        $this->assertDatabaseEmpty('alert_rules');
    }

    public function test_paid_plan_can_create_telemetry_guardrail_rules(): void
    {
        $environment = Environment::factory()->create();
        $account = $environment->project->account;
        $this->onMonitoringTier($account, 'pro');

        $this->actingAs($this->ownerOf($account))->postRule([
            'name' => 'Telemetry heartbeat', 'environment_id' => $environment->id, 'metric' => 'telemetry_freshness',
            'threshold' => 300, 'window_minutes' => 5, 'minimum_samples' => 1,
            'trigger_checks' => 1, 'recovery_checks' => 1, 'enabled' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('alert_rules', ['metric' => 'telemetry_freshness', 'threshold' => 300]);
    }
}
