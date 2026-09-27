<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AlertMetric;
use App\Enums\AuditAction;
use App\Models\AlertRule;
use App\Models\MetricSeries;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Services\Monitoring\IncidentLifecycle;
use App\Services\Monitoring\MonitorChanges;
use App\Services\Monitoring\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class SaveAlertRule
{
    /** Changing any of these starts the rule over and closes its open incident as "rule changed". */
    public const CONDITIONS = ['metric', 'service', 'match_text', 'threshold', 'window_minutes', 'minimum_samples', 'trigger_checks', 'recovery_checks', 'metric_series_id', 'numeric_threshold', 'aggregation', 'comparison', 'freshness_seconds', 'service_level_objective_id'];

    public function __construct(
        private readonly TelemetryRedactor $redactor,
        private readonly IncidentLifecycle $lifecycle,
        private readonly MonitorChanges $changes,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * Create or change an alert rule on one of the project's environments.
     *
     * @param  array<string, mixed>  $data  validated by AlertRuleRequest
     */
    public function handle(Project $project, User $actor, array $data, ?AlertRule $rule = null): AlertRule
    {
        return DB::transaction(function () use ($project, $actor, $data, $rule): AlertRule {
            $environment = $this->changes->lockScope($project, $actor, (string) $data['environment_id']);
            if ($rule !== null) {
                $rule = AlertRule::query()->whereBelongsTo($environment)->lockForUpdate()->findOrFail($rule->id);
                abort_unless($rule->state_version === (int) $data['version'], 409, __('This alert rule changed. Refresh before trying again.'));
            }

            $now = CarbonImmutable::now('UTC');
            $isNew = $rule === null;
            $rule ??= new AlertRule;
            $data['name'] = $this->redactor->redact(['name' => $data['name']])['name'];
            $data['service'] = $data['service'] ?? null;
            $data['match_text'] = $data['match_text'] ?? null;
            if ($data['metric'] === AlertMetric::SloBurnRate->value) {
                $objective = ServiceLevelObjective::query()->where('environment_id', $environment->id)
                    ->where('enabled', true)->whereKey((int) $data['service_level_objective_id'])->firstOrFail();
                $data['service_level_objective_id'] = $objective->id;
                $data['service'] = null;
                $data['match_text'] = null;
                $data = [...$data, 'metric_series_id' => null, 'numeric_threshold' => null, 'aggregation' => null, 'comparison' => null, 'freshness_seconds' => null];
            } elseif (in_array($data['metric'], [AlertMetric::NumericMetric->value, AlertMetric::MetricAnomaly->value], true)) {
                MetricSeries::query()->where('environment_id', $environment->id)->lockForUpdate()->whereKey((int) $data['metric_series_id'])->firstOrFail();
                $data['service'] = null;
                $data['match_text'] = null;
                $data['service_level_objective_id'] = null;
                if ($data['metric'] === AlertMetric::NumericMetric->value) {
                    $data['numeric_threshold'] = (float) $data['threshold'];
                    $data['threshold'] = 0;
                } else {
                    $data = [...$data, 'numeric_threshold' => null, 'aggregation' => null, 'comparison' => null, 'freshness_seconds' => null];
                }
            } else {
                if ($data['metric'] !== AlertMetric::LogPatternCount->value) {
                    $data['match_text'] = null;
                }
                $data = [...$data, 'metric_series_id' => null, 'numeric_threshold' => null, 'aggregation' => null, 'comparison' => null, 'freshness_seconds' => null, 'service_level_objective_id' => null];
            }
            $rule->fill(Arr::only($data, ['name', 'enabled', ...self::CONDITIONS]));
            if (! $isNew && ! $rule->isDirty()) {
                return $rule;
            }

            $conditionsChanged = ! $isNew && $rule->isDirty(self::CONDITIONS);
            $enabledChanged = ! $isNew && $rule->isDirty('enabled');
            $incident = ! $isNew ? $rule->incidents()->where('active_slot', true)->lockForUpdate()->first() : null;
            if ($incident !== null && $conditionsChanged) {
                $this->lifecycle->close($incident, 'rule_changed', $now, $actor);
            } elseif ($incident !== null && $enabledChanged) {
                $incident->activities()->create(['actor_id' => $actor->id, 'action' => $rule->enabled ? 'rule_resumed' : 'rule_paused']);
            }

            if ($isNew || $conditionsChanged || $enabledChanged) {
                $rule->forceFill([
                    'monitoring_since' => $now, 'next_evaluation_at' => $now->startOfMinute()->addMinute(),
                    'evaluation_state' => 'warming', 'breach_streak' => 0, 'recovery_streak' => 0,
                    'evaluated_until' => null, 'checked_at' => null, 'observation' => null,
                ]);
            }
            $rule->forceFill(['environment_id' => $environment->id, 'state_version' => $isNew ? 0 : $rule->state_version + 1])->save();
            $this->audit->handle($isNew ? AuditAction::AlertRuleCreated : AuditAction::AlertRuleUpdated, $actor, $project->account_id, [
                'rule' => $rule->name, 'project' => $project->name, 'metric' => $rule->metric->value, 'environment' => $environment->name, 'enabled' => $rule->enabled,
            ], $project->id);

            return $rule;
        }, attempts: 3);
    }
}
