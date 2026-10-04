<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Models\AlertRule;
use App\Models\MetricSample;
use App\Models\MetricSeries;
use App\Models\TelemetryEvent;
use App\Services\Monitoring\AlertRuleEvaluator;
use App\Services\Telemetry\MetricProjection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class MetricAnomalyAlertTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Paid anomaly rule opens an incident for a large series shift.
     */
    public function test_paid_anomaly_rule_opens_an_incident_for_a_large_series_shift(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $series = MetricSeries::factory()->create(['kind' => 'gauge']);
        for ($minute = 40; $minute <= 48; $minute++) {
            $this->sample($series, '2026-09-21T11:'.$minute.':00Z', 10);
        }
        for ($minute = 49; $minute <= 57; $minute++) {
            $this->sample($series, '2026-09-21T11:'.$minute.':00Z', 10);
        }
        $this->sample($series, '2026-09-21T11:58:00Z', 100);
        $account = $series->environment->project->account;
        $this->onMonitoringTier($account, 'pro');
        $rule = AlertRule::factory()->for($series->environment)->ready()->create([
            'metric' => 'metric_anomaly', 'metric_series_id' => $series->id, 'threshold' => 3.5,
            'window_minutes' => 10, 'minimum_samples' => 1, 'trigger_checks' => 1, 'recovery_checks' => 1,
        ]);

        app(AlertRuleEvaluator::class)->evaluate();

        $rule->refresh();
        $this->assertSame('breaching', $rule->evaluation_state);
        $this->assertGreaterThan(3.5, (float) $this->observed($rule, 'value'));
        $incident = $rule->incidents()->sole();
        $this->assertSame('metric_anomaly', $incident->rule_snapshot['metric']);
        $this->assertSame($series->id, $incident->rule_snapshot['metric_series_id']);
    }

    /**
     * Free plan cannot create a metric anomaly alert rule.
     */
    public function test_free_plan_cannot_create_a_metric_anomaly_alert_rule(): void
    {
        $series = MetricSeries::factory()->create(['kind' => 'gauge']);

        $this->actingAs($this->ownerOf($series->environment->project->account))->postRule([
            'name' => 'CPU anomaly', 'environment_id' => $series->environment_id, 'metric' => 'metric_anomaly',
            'metric_series_id' => $series->id, 'threshold' => 3.5, 'window_minutes' => 5,
            'minimum_samples' => 1, 'trigger_checks' => 1, 'recovery_checks' => 1, 'enabled' => 1,
        ])->assertJsonValidationErrors(['metric' => 'Metric anomaly alerts come with Monitoring Pro and above.']);

        $this->assertDatabaseEmpty('alert_rules');
    }

    /**
     * Paid plan can link a metric anomaly alert to a gauge series.
     */
    public function test_paid_plan_can_link_a_metric_anomaly_alert_to_a_gauge_series(): void
    {
        $series = MetricSeries::factory()->create(['kind' => 'gauge']);
        $account = $series->environment->project->account;
        $this->onMonitoringTier($account, 'pro');

        $this->actingAs($this->ownerOf($account))->postRule([
            'name' => 'CPU anomaly', 'environment_id' => $series->environment_id, 'metric' => 'metric_anomaly',
            'metric_series_id' => $series->id, 'threshold' => 3.5, 'window_minutes' => 5,
            'minimum_samples' => 1, 'trigger_checks' => 1, 'recovery_checks' => 1, 'enabled' => 1,
        ])->assertSuccessful();

        $rule = AlertRule::query()->sole();
        $this->assertSame('metric_anomaly', $rule->metric->value);
        $this->assertSame($series->id, $rule->metric_series_id);
        $this->assertNull($rule->aggregation);
    }

    private function sample(MetricSeries $series, string $time, float $value): MetricSample
    {
        $event = TelemetryEvent::factory()->for($series->environment)->create(['type' => 'metric', 'occurred_at' => $time]);

        return MetricSample::factory()->for($series)->for($event, 'telemetryEvent')->create([
            'time_key' => MetricProjection::timeKey(CarbonImmutable::parse($time)),
            'value' => $value, 'state' => 'valid', 'value_hash' => hash('sha256', 'valid|'.$value),
            'occurred_at' => $time, 'received_at' => $time,
        ]);
    }
}
