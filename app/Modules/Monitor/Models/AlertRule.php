<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Data\Telemetry\AlertMetric;
use App\Modules\Monitor\Database\Factories\AlertRuleFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use App\Modules\Monitor\Models\Concerns\HasProjectVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'metric', 'service', 'match_text', 'threshold', 'window_minutes', 'minimum_samples', 'trigger_checks', 'recovery_checks', 'enabled', 'metric_series_id', 'numeric_threshold', 'aggregation', 'comparison', 'freshness_seconds', 'service_level_objective_id'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory, SoftDeletes;

    use HasProjectVisibility;

    /** @param Builder<AlertRule> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereIn('environment_id', Environment::forWorkspace($workspace)->select('id'));
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<ServiceLevelObjective, $this> */
    public function serviceLevelObjective(): BelongsTo
    {
        return $this->belongsTo(ServiceLevelObjective::class);
    }

    /** @return BelongsTo<MetricSeries, $this> */
    public function metricSeries(): BelongsTo
    {
        return $this->belongsTo(MetricSeries::class);
    }

    public function thresholdValue(): ?float
    {
        return $this->metric === AlertMetric::NumericMetric ? $this->numeric_threshold : $this->threshold;
    }

    public function comparisonLabel(): string
    {
        return in_array($this->metric, [AlertMetric::TelemetryVolume], true)
            || ($this->metric === AlertMetric::NumericMetric && $this->comparison === 'lte') ? '≤' : '≥';
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /** @return BelongsToMany<AlertDestination, $this> */
    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(AlertDestination::class)->withPivot(['opened', 'recovered']);
    }

    /** @return HasMany<AlertEscalation, $this> */
    public function escalations(): HasMany
    {
        return $this->hasMany(AlertEscalation::class)->orderBy('position')->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $snapshot = $this->only(['name', 'metric', 'service', 'match_text', 'threshold', 'window_minutes', 'minimum_samples', 'trigger_checks', 'recovery_checks']);
        if (in_array($this->metric, [AlertMetric::NumericMetric, AlertMetric::MetricAnomaly], true)) {
            $snapshot = [
                ...$snapshot, 'threshold' => $this->thresholdValue(),
                ...$this->only(['metric_series_id']),
                'metric_series_name' => $this->metricSeries?->name,
                'metric_resource_label' => $this->metricSeries?->resource_label,
            ];
            if ($this->metric === AlertMetric::NumericMetric) {
                $snapshot = [...$snapshot, ...$this->only(['aggregation', 'comparison', 'freshness_seconds'])];
            }
        } elseif ($this->metric === AlertMetric::SloBurnRate) {
            $snapshot['service_level_objective_id'] = $this->service_level_objective_id;
        }

        return $snapshot;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metric' => AlertMetric::class, 'threshold' => 'float', 'enabled' => 'boolean',
            'numeric_threshold' => 'float', 'freshness_seconds' => 'integer', 'metric_series_id' => 'integer',
            'window_minutes' => 'integer', 'minimum_samples' => 'integer', 'trigger_checks' => 'integer', 'recovery_checks' => 'integer',
            'service_level_objective_id' => 'integer',
            'state_version' => 'integer', 'breach_streak' => 'integer', 'recovery_streak' => 'integer', 'observation' => 'array',
            'monitoring_since' => 'immutable_datetime', 'next_evaluation_at' => 'immutable_datetime',
            'evaluated_until' => 'immutable_datetime', 'checked_at' => 'immutable_datetime',
        ];
    }
}
