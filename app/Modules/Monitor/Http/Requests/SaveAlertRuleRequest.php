<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Data\Telemetry\AlertMetric;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\MetricSeries;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveAlertRuleRequest extends FormRequest
{
    public const WINDOWS = [1 => '1 minute', 5 => '5 minutes', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour'];

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $workspace = $currentWorkspace->get();
        if ($rule = $this->route('alertRule')) {
            abort_unless($rule instanceof AlertRule && AlertRule::forWorkspace($workspace)->whereKey($rule->id)->exists(), 404);
            Gate::authorize('update', $rule);
        } else {
            Gate::authorize('create', [AlertRule::class, $workspace]);
        }

        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(CurrentWorkspace $currentWorkspace): array
    {
        $environmentIds = Environment::forWorkspace($currentWorkspace->get())->select('id');
        $metric = is_string($this->input('metric')) ? AlertMetric::tryFrom($this->input('metric')) : null;
        $thresholdMinimum = match ($metric) {
            AlertMetric::NumericMetric => 'min:-1000000000000000',
            AlertMetric::TelemetryVolume => 'min:0',
            AlertMetric::MetricAnomaly => 'between:3,20',
            AlertMetric::LogPatternCount => 'min:1',
            default => 'gt:0',
        };

        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'environment_id' => ['required', 'integer', Rule::exists('monitor.environments', 'id')->where(fn (Builder $query): Builder => $query->whereIn('id', $environmentIds))],
            'metric' => ['required', Rule::enum(AlertMetric::class)],
            'service' => ['nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'match_text' => [Rule::excludeIf($metric !== AlertMetric::LogPatternCount), 'required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'service_level_objective_id' => [Rule::excludeIf($metric !== AlertMetric::SloBurnRate), 'required', 'integer', Rule::exists('monitor.service_level_objectives', 'id')->where(fn (Builder $query): Builder => $query->whereIn('environment_id', $environmentIds)->whereNull('deleted_at'))],
            'threshold' => ['required', 'numeric', 'decimal:0,3', $thresholdMinimum, 'max:'.($metric?->maximum() ?? 1000000000), ...($metric?->isCount() ? ['integer'] : [])],
            'metric_series_id' => [Rule::excludeIf(! in_array($metric, [AlertMetric::NumericMetric, AlertMetric::MetricAnomaly], true)), 'required', 'integer', Rule::exists('monitor.metric_series', 'id')->where(fn (Builder $query): Builder => $query->whereIn('environment_id', $environmentIds))],
            'aggregation' => ['exclude_unless:metric,numeric_metric', 'required', Rule::in(['last', 'mean', 'min', 'max', 'rate'])],
            'comparison' => ['exclude_unless:metric,numeric_metric', 'required', Rule::in(['gte', 'lte'])],
            'freshness_seconds' => ['exclude_unless:metric,numeric_metric', 'required', 'integer', 'between:30,3600'],
            'window_minutes' => ['required', 'integer', Rule::in(array_keys(self::WINDOWS))],
            'minimum_samples' => ['required', 'integer', 'between:1,1000000'],
            'trigger_checks' => ['required', 'integer', 'between:1,10'],
            'recovery_checks' => ['required', 'integer', 'between:1,10'],
            'enabled' => ['required', 'boolean'],
            'version' => [$this->route('alertRule') ? 'required' : 'exclude', 'integer', 'min:0'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(TelemetryRedactor $redactor, CurrentWorkspace $currentWorkspace, WorkspacePlanLimits $limits): array
    {
        return [function (Validator $validator) use ($redactor, $currentWorkspace, $limits): void {
            $metric = AlertMetric::tryFrom((string) $this->input('metric'));
            if ($metric?->isTelemetryGuardrail() && ! $limits->telemetryGuardrailsEnabled($currentWorkspace->get())) {
                $validator->errors()->add('metric', 'Telemetry volume and freshness alerts are available on Pro and above.');
            }
            if ($metric?->isSloBurnRate() && ! $limits->sloBurnRateAlertsEnabled($currentWorkspace->get())) {
                $validator->errors()->add('metric', 'SLO burn-rate alerts are available on Pro and above.');
            }
            if ($metric?->isAnomaly() && ! $limits->anomalyDetectionEnabled($currentWorkspace->get())) {
                $validator->errors()->add('metric', 'Metric anomaly alerts are available on Pro and above.');
            }
            if ($metric === AlertMetric::LogPatternCount && ! $limits->logPatternAlertsEnabled($currentWorkspace->get())) {
                $validator->errors()->add('metric', 'Log pattern alerts are available on Pro and above.');
            }
            if ($metric?->isSloBurnRate() && ! $validator->errors()->hasAny(['environment_id', 'service_level_objective_id'])) {
                $objective = ServiceLevelObjective::forWorkspace($currentWorkspace->get())->find($this->input('service_level_objective_id'));
                if ($objective === null || ! $objective->enabled) {
                    $validator->errors()->add('service_level_objective_id', 'Choose an enabled SLO from the selected environment.');
                } elseif ((int) $this->input('environment_id') !== $objective->environment_id) {
                    $validator->errors()->add('service_level_objective_id', 'The SLO must belong to the selected environment.');
                }
            }
            if ($metric === AlertMetric::LogPatternCount && ! $validator->errors()->has('match_text')
                && $redactor->redact(['match_text' => $this->input('match_text')])['match_text'] !== $this->input('match_text')) {
                $validator->errors()->add('match_text', 'Use a log pattern without secrets or sensitive values.');
            }
            if (in_array($metric, [AlertMetric::NumericMetric, AlertMetric::MetricAnomaly], true) && ! $validator->errors()->hasAny(['environment_id', 'metric_series_id'])) {
                $series = MetricSeries::query()->where('environment_id', $this->input('environment_id'))->find($this->input('metric_series_id'));
                if ($series === null) {
                    $validator->errors()->add('metric_series_id', 'Choose a metric series from the selected environment.');
                } elseif ($metric === AlertMetric::MetricAnomaly && ! ($series->kind === 'gauge' || ($series->kind === 'sum' && $series->temporality === 'delta'))) {
                    $validator->errors()->add('metric_series_id', 'Anomaly alerts require a gauge or delta sum metric series.');
                } elseif ($metric === AlertMetric::NumericMetric && (! in_array($series->kind, ['gauge', 'sum'], true) || ($this->input('aggregation') === 'rate' && ! $series->supportsRate()))) {
                    $validator->errors()->add('aggregation', 'This series does not support the selected numeric calculation.');
                }
            }
            $rule = $this->route('alertRule');
            if ($rule instanceof AlertRule && ! $validator->errors()->has('environment_id') && (int) $this->input('environment_id') !== $rule->environment_id) {
                $validator->errors()->add('environment_id', 'The environment cannot be changed. Create a separate rule.');
            }
            if (! $validator->errors()->has('service') && $redactor->redact(['service' => $this->input('service')])['service'] !== $this->input('service')) {
                $validator->errors()->add('service', 'Use a service label without secrets.');
            }
        }];
    }
}
