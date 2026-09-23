<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\AlertMetric;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\MetricSeries;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ChangeAlertRule
{
    private const CONDITIONS = ['metric', 'service', 'match_text', 'threshold', 'window_minutes', 'minimum_samples', 'trigger_checks', 'recovery_checks', 'metric_series_id', 'numeric_threshold', 'aggregation', 'comparison', 'freshness_seconds', 'service_level_objective_id'];

    public function __construct(private readonly TelemetryRedactor $redactor, private readonly ChangeIncident $incidents, private readonly RecordAuditLog $audit) {}

    /** @param array<string, mixed> $data */
    public function save(Workspace $workspace, User $actor, array $data, ?AlertRule $rule = null): AlertRule
    {
        return DB::connection('monitor')->transaction(function () use ($workspace, $actor, $data, $rule): AlertRule {
            [$workspace, $environment] = $this->lockScope($workspace, $actor, (int) $data['environment_id']);
            if ($rule !== null) {
                $rule = AlertRule::query()->whereBelongsTo($environment)->lockForUpdate()->findOrFail($rule->id);
                $this->checkVersion($rule, (int) $data['version']);
            }

            $now = CarbonImmutable::now('UTC');
            $isNew = $rule === null;
            $rule ??= new AlertRule;
            $data['name'] = $this->redactor->redact(['name' => $data['name']])['name'];
            $data['service'] = $data['service'] ?? null;
            $data['match_text'] = $data['match_text'] ?? null;
            if ($data['metric'] === AlertMetric::SloBurnRate->value) {
                $objective = ServiceLevelObjective::forWorkspace($workspace)->where('environment_id', $environment->id)
                    ->where('enabled', true)->findOrFail($data['service_level_objective_id']);
                $data['service_level_objective_id'] = $objective->id;
                $data['service'] = null;
                $data['match_text'] = null;
                $data = [...$data, 'metric_series_id' => null, 'numeric_threshold' => null, 'aggregation' => null, 'comparison' => null, 'freshness_seconds' => null];
            } elseif (in_array($data['metric'], [AlertMetric::NumericMetric->value, AlertMetric::MetricAnomaly->value], true)) {
                MetricSeries::query()->where('environment_id', $environment->id)->lockForUpdate()->findOrFail($data['metric_series_id']);
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
                $this->incidents->close($incident, 'rule_changed', $now, $actor);
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
            $this->audit->record($workspace, $actor, $isNew ? 'alert_rule.created' : 'alert_rule.updated', $rule, [
                'label' => $rule->name, 'metric' => $rule->metric->value, 'environment' => $environment->name, 'enabled' => $rule->enabled,
            ]);

            return $rule;
        }, attempts: 3);
    }

    public function archive(AlertRule $rule, Workspace $workspace, User $actor, int $version): void
    {
        DB::connection('monitor')->transaction(function () use ($rule, $workspace, $actor, $version): void {
            [, $environment] = $this->lockScope($workspace, $actor, $rule->environment_id);
            $rule = AlertRule::query()->whereBelongsTo($environment)->lockForUpdate()->findOrFail($rule->id);
            $this->checkVersion($rule, $version);
            $incident = $rule->incidents()->where('active_slot', true)->lockForUpdate()->first();
            if ($incident !== null) {
                $this->incidents->close($incident, 'rule_archived', CarbonImmutable::now('UTC'), $actor);
            }
            $rule->forceFill(['enabled' => false, 'state_version' => $rule->state_version + 1])->save();
            $rule->delete();
            $this->audit->record($workspace, $actor, 'alert_rule.archived', $rule, ['label' => $rule->name, 'metric' => $rule->metric->value, 'environment' => $environment->name]);
        }, attempts: 3);
    }

    /** @return array{Workspace, Environment} */
    private function lockScope(Workspace $workspace, User $actor, int $environmentId): array
    {
        $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
        Gate::forUser($actor)->authorize('update', $workspace);
        $environment = Environment::forWorkspace($workspace)->findOrFail($environmentId);
        $application = Application::query()->whereBelongsTo($workspace)->lockForUpdate()->findOrFail($environment->application_id);
        $environment = Environment::query()->whereBelongsTo($application)->lockForUpdate()->findOrFail($environmentId);

        return [$workspace, $environment];
    }

    private function checkVersion(AlertRule $rule, int $version): void
    {
        abort_unless($rule->state_version === $version, 409, 'This alert rule changed. Refresh before trying again.');
    }
}
