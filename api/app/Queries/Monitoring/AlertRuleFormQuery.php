<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Enums\AlertMetric;
use App\Models\AlertRule;
use App\Models\MetricSeries;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Services\Billing\Entitlements;

final readonly class AlertRuleFormQuery
{
    /**
     * Create a new AlertRuleFormQuery instance.
     *
     * @param  ProjectAlertRulesQuery  $rules
     * @param  Entitlements  $entitlements
     */
    public function __construct(private ProjectAlertRulesQuery $rules, private Entitlements $entitlements) {}

    /**
     * Describe the form for adding or editing an alert rule: what it can watch (with the kinds the plan doesn't include
     * marked), its windows, the project's objectives and metrics, and (when editing) the rule's settings.
     *
     * @param  Project  $project
     * @param  AlertRule|null  $rule
     * @return array<string, mixed>
     */
    public function handle(Project $project, ?AlertRule $rule): array
    {
        $plan = $this->entitlements->for($project->account);

        return [
            'metrics' => array_map(fn (AlertMetric $metric): array => [
                'value' => $metric->value,
                'label' => __($metric->label()),
                // The extra field the metric needs, so the form shows only that one.
                'needs' => match ($metric) {
                    AlertMetric::LogPatternCount => 'match',
                    AlertMetric::SloBurnRate => 'objective',
                    AlertMetric::NumericMetric => 'numeric',
                    AlertMetric::MetricAnomaly => 'series',
                    default => null,
                },
                'disabled' => ($metric->isTelemetryGuardrail() && ! $plan->has('monitoring.guardrails')) || ($metric->isSloBurnRate() && ! $plan->has('monitoring.slo_burn_rate'))
                    || ($metric->isAnomaly() && ! $plan->has('monitoring.anomalies')) || ($metric === AlertMetric::LogPatternCount && ! $plan->has('monitoring.log_patterns')),
            ], AlertMetric::cases()),
            'windows' => array_map(fn (int $minutes, string $label): array => ['value' => (string) $minutes, 'label' => __($label)], array_keys(AlertRule::WINDOWS), AlertRule::WINDOWS),
            'objectives' => array_map(fn (ServiceLevelObjective $objective): array => ['value' => (string) $objective->id, 'label' => $objective->name.' · '.$objective->environment->name], $this->rules->objectives($project)),
            'series' => MetricSeries::query()->whereIn('environment_id', $project->environments()->select('id'))->orderBy('name')->limit(500)->get()
                ->map(fn (MetricSeries $series): array => ['value' => (string) $series->id, 'label' => $series->name.' · '.$series->resource_label])->values(),
            'rule' => $rule === null ? null : [
                'id' => $rule->id,
                'name' => $rule->name,
                'environmentId' => $rule->environment_id,
                'version' => $rule->state_version,
                'metric' => $rule->metric->value,
                'threshold' => $rule->thresholdValue(),
                'windowMinutes' => $rule->window_minutes,
                'service' => $rule->service,
                'minimumSamples' => $rule->minimum_samples,
                'matchText' => $rule->match_text,
                'objectiveId' => $rule->service_level_objective_id,
                'seriesId' => $rule->metric_series_id,
                'aggregation' => $rule->aggregation,
                'comparison' => $rule->comparison,
                'freshnessSeconds' => $rule->freshness_seconds,
                'triggerChecks' => $rule->trigger_checks,
                'recoveryChecks' => $rule->recovery_checks,
                'enabled' => (bool) $rule->enabled,
            ],
        ];
    }
}
