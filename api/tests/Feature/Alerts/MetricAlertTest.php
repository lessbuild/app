<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Enums\AlertMetric;
use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\MetricSample;
use App\Models\MetricSeries;
use App\Models\TelemetryEvent;
use App\Services\Monitoring\AlertRuleEvaluator;
use App\Services\Telemetry\MetricProjection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class MetricAlertTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Numeric series threshold opens an incident with series evidence.
     */
    public function test_numeric_series_threshold_opens_an_incident_with_series_evidence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $series = MetricSeries::factory()->create(['kind' => 'gauge']);
        $this->sample($series, '2026-09-21T11:58:00Z', 0.91);
        $rule = AlertRule::factory()->ready()->create([
            'environment_id' => $series->environment_id, 'metric' => AlertMetric::NumericMetric,
            'metric_series_id' => $series->id, 'threshold' => 0, 'numeric_threshold' => 0.9,
            'aggregation' => 'last', 'comparison' => 'gte', 'freshness_seconds' => 120,
            'minimum_samples' => 1, 'trigger_checks' => 1,
        ]);

        $this->app->make(AlertRuleEvaluator::class)->evaluate();

        $incident = Incident::query()->sole();
        $this->assertSame('open', $incident->status);
        $this->assertSame(0.91, (float) $incident->opening_observation['value']);
        $this->assertSame($series->id, $incident->rule_snapshot['metric_series_id']);
        $this->assertSame('numeric_metric', $incident->rule_snapshot['metric']);
    }

    /**
     * Stale numeric series is unknown and does not prove recovery.
     */
    public function test_stale_numeric_series_is_unknown_and_does_not_prove_recovery(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $series = MetricSeries::factory()->create(['kind' => 'gauge']);
        $this->sample($series, '2026-09-21T11:55:00Z', 0.1);
        $rule = AlertRule::factory()->ready()->create([
            'environment_id' => $series->environment_id, 'metric' => AlertMetric::NumericMetric,
            'metric_series_id' => $series->id, 'threshold' => 0, 'numeric_threshold' => 0.9,
            'aggregation' => 'last', 'comparison' => 'gte', 'freshness_seconds' => 60,
            'minimum_samples' => 1, 'trigger_checks' => 1,
        ]);
        $incident = Incident::factory()->for($rule)->create();

        $this->app->make(AlertRuleEvaluator::class)->evaluate();

        $this->assertSame('no_data', $this->observed($rule, 'state'));
        $this->assertSame('stale', $this->observed($rule, 'reason'));
        $this->assertSame('open', $this->reload($incident)->status);
    }

    /**
     * Numeric alert form persists a scoped series and lower bound comparison.
     */
    public function test_numeric_alert_form_persists_a_scoped_series_and_lower_bound_comparison(): void
    {
        $series = MetricSeries::factory()->create(['name' => 'system.filesystem.utilization']);
        $user = $this->ownerOf($series->environment->project->account);

        $this->actingAs($user)->postRule([
            'name' => 'Disk nearly full', 'environment_id' => $series->environment_id,
            'metric' => 'numeric_metric', 'metric_series_id' => $series->id,
            'threshold' => 0.1, 'aggregation' => 'last', 'comparison' => 'lte',
            'freshness_seconds' => 120, 'window_minutes' => 5, 'minimum_samples' => 1,
            'trigger_checks' => 1, 'recovery_checks' => 1, 'enabled' => 1,
        ])->assertSuccessful();

        $rule = AlertRule::query()->sole();
        $this->assertSame(AlertMetric::NumericMetric, $rule->metric);
        $this->assertSame(0.1, (float) $rule->numeric_threshold);
        $this->assertSame('lte', $rule->comparison);
        $this->assertSame($series->id, $rule->metric_series_id);
    }

    private function sample(MetricSeries $series, string $time, float $value): MetricSample
    {
        $event = TelemetryEvent::factory()->for($series->environment)->create(['type' => 'metric', 'occurred_at' => $time]);

        return MetricSample::factory()->for($series)->for($event, 'telemetryEvent')->create([
            'time_key' => MetricProjection::timeKey(CarbonImmutable::parse($time)),
            'value' => $value, 'state' => 'valid',
            'value_hash' => hash('sha256', 'valid|'.$value),
            'occurred_at' => $time, 'received_at' => $time,
        ]);
    }
}
