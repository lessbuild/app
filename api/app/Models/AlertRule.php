<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertMetric;
use Carbon\CarbonImmutable;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $environment_id
 * @property string $name
 * @property AlertMetric $metric
 * @property string|null $service
 * @property string|null $match_text
 * @property float $threshold
 * @property float|null $numeric_threshold
 * @property int|null $metric_series_id
 * @property string|null $aggregation
 * @property string|null $comparison
 * @property int|null $freshness_seconds
 * @property int|null $service_level_objective_id
 * @property int $window_minutes
 * @property int $minimum_samples
 * @property int $trigger_checks
 * @property int $recovery_checks
 * @property bool $enabled
 * @property int $state_version
 * @property string $evaluation_state
 * @property int $breach_streak
 * @property int $recovery_streak
 * @property CarbonImmutable $monitoring_since
 * @property CarbonImmutable $next_evaluation_at
 * @property CarbonImmutable|null $evaluated_until
 * @property CarbonImmutable|null $checked_at
 * @property array<string, mixed>|null $observation
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property mixed $deleted_at
 * @property-read Environment $environment
 * @property-read ServiceLevelObjective|null $serviceLevelObjective
 * @property-read MetricSeries|null $metricSeries
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Incident> $incidents
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AlertDestination> $destinations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AlertEscalation> $escalations
 */
#[Fillable(['name', 'metric', 'service', 'match_text', 'threshold', 'window_minutes', 'minimum_samples', 'trigger_checks', 'recovery_checks', 'enabled', 'metric_series_id', 'numeric_threshold', 'aggregation', 'comparison', 'freshness_seconds', 'service_level_objective_id'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(AlertRuleFactory::class)]
final class AlertRule extends Model
{
    /** The windows a rule can judge over, in minutes, with English labels. */
    public const WINDOWS = [1 => '1 minute', 5 => '5 minutes', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour'];

    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Limit a query to rules in the account's environments.
     *
     * @param  Builder<AlertRule>  $query
     * @param  Account  $account
     * @return void
     */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereIn('environment_id', Environment::forAccount($account)->select('id'));
    }

    /**
     * Get the environment whose telemetry the rule watches.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the SLO a burn-rate rule watches.
     *
     * @return BelongsTo<ServiceLevelObjective, $this>
     */
    public function serviceLevelObjective(): BelongsTo
    {
        return $this->belongsTo(ServiceLevelObjective::class);
    }

    /**
     * Get the metric series a numeric or anomaly rule watches.
     *
     * @return BelongsTo<MetricSeries, $this>
     */
    public function metricSeries(): BelongsTo
    {
        return $this->belongsTo(MetricSeries::class);
    }

    /**
     * Get the threshold to compare against. Numeric-metric rules keep theirs in `numeric_threshold`, a double, since
     * resource metrics can be far larger or smaller than the other metrics' thresholds.
     *
     * @return float|null
     */
    public function thresholdValue(): ?float
    {
        return $this->metric === AlertMetric::NumericMetric ? $this->numeric_threshold : $this->threshold;
    }

    /**
     * Get the comparison sign: "≤" for rules that fire when a value drops (telemetry volume, or numeric rules set to
     * less-than), "≥" otherwise.
     *
     * @return string
     */
    public function comparisonLabel(): string
    {
        return in_array($this->metric, [AlertMetric::TelemetryVolume], true)
            || ($this->metric === AlertMetric::NumericMetric && $this->comparison === 'lte') ? '≤' : '≥';
    }

    /**
     * Get the incidents the rule has opened.
     *
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * Get the destinations the rule sends alerts to.
     *
     * @return BelongsToMany<AlertDestination, $this>
     */
    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(AlertDestination::class)->withPivot(['opened', 'recovered']);
    }

    /**
     * Get who is notified, and when, while an incident stays open.
     *
     * @return HasMany<AlertEscalation, $this>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(AlertEscalation::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Capture the rule's settings as they are when an incident opens, stored on the incident so later edits don't
     * change what the incident says it was checking.
     *
     * @return array<string, mixed>
     */
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

    /**
     * Get the attributes that should be cast.
     *
     * Reads `metric` as an AlertMetric and `observation` as JSON.
     *
     * @return array<string, string>
     */
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
