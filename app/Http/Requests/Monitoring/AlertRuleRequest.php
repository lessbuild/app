<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Enums\AlertMetric;
use App\Models\AlertRule;
use App\Models\Environment;
use App\Models\MetricSeries;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\TelemetryRedactor;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Authorisation happens in SaveAlertRule; paid rule types are checked against the account's Monitoring tier here. */
final class AlertRuleRequest extends FormRequest
{
    public const WINDOWS = [1 => '1 minute', 5 => '5 minutes', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour'];

    /**
     * The JSON body for API calls, the form fields otherwise.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    /**
     * An alert rule's settings. Which fields are required, and the threshold's range, depend on the metric; SLOs and
     * series must belong to the project's environments.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $environmentIds = Environment::query()->where('project_id', $this->project()->id)->select('id');
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
            'environment_id' => ['required', 'string', Rule::exists('environments', 'id')->where(fn (Builder $query): Builder => $query->whereIn('id', $environmentIds))],
            'metric' => ['required', Rule::enum(AlertMetric::class)],
            'service' => ['nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'match_text' => [Rule::excludeIf($metric !== AlertMetric::LogPatternCount), 'required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'service_level_objective_id' => [Rule::excludeIf($metric !== AlertMetric::SloBurnRate), 'required', 'integer', Rule::exists('service_level_objectives', 'id')->where(fn (Builder $query): Builder => $query->whereIn('environment_id', $environmentIds)->whereNull('deleted_at'))],
            'threshold' => ['required', 'numeric', 'decimal:0,3', $thresholdMinimum, 'max:'.($metric?->maximum() ?? 1000000000), ...($metric?->hasWholeNumberThreshold() ? ['integer'] : [])],
            'metric_series_id' => [Rule::excludeIf(! in_array($metric, [AlertMetric::NumericMetric, AlertMetric::MetricAnomaly], true)), 'required', 'integer', Rule::exists('metric_series', 'id')->where(fn (Builder $query): Builder => $query->whereIn('environment_id', $environmentIds))],
            'aggregation' => ['exclude_unless:metric,numeric_metric', 'required', Rule::in(['last', 'mean', 'min', 'max', 'rate'])],
            'comparison' => ['exclude_unless:metric,numeric_metric', 'required', Rule::in(['gte', 'lte'])],
            'freshness_seconds' => ['exclude_unless:metric,numeric_metric', 'required', 'integer', 'between:30,3600'],
            'window_minutes' => ['required', 'integer', Rule::in(array_keys(self::WINDOWS))],
            'minimum_samples' => ['required', 'integer', 'between:1,1000000'],
            'trigger_checks' => ['required', 'integer', 'between:1,10'],
            'recovery_checks' => ['required', 'integer', 'between:1,10'],
            'enabled' => ['required', 'boolean'],
            'version' => [$this->route('rule') !== null ? 'required' : 'exclude', 'integer', 'min:0'],
        ];
    }

    /**
     * Checks the plan includes the metric chosen, that an SLO or series fits the rule (enabled, same environment, the
     * right kind of series for the calculation), that an existing rule's environment isn't changed, and that labels and
     * patterns contain no secrets.
     *
     * @return array<callable(Validator): void>
     */
    public function after(TelemetryRedactor $redactor, Entitlements $entitlements): array
    {
        return [function (Validator $validator) use ($redactor, $entitlements): void {
            $plan = $entitlements->for($this->project()->account);
            $metric = AlertMetric::tryFrom((string) $this->input('metric'));
            if ($metric?->isTelemetryGuardrail() && ! $plan->has('monitoring.guardrails')) {
                $validator->errors()->add('metric', __('Telemetry volume and freshness alerts come with Monitoring Pro and above.'));
            }
            if ($metric?->isSloBurnRate() && ! $plan->has('monitoring.slo_burn_rate')) {
                $validator->errors()->add('metric', __('SLO burn-rate alerts come with Monitoring Pro and above.'));
            }
            if ($metric?->isAnomaly() && ! $plan->has('monitoring.anomalies')) {
                $validator->errors()->add('metric', __('Metric anomaly alerts come with Monitoring Pro and above.'));
            }
            if ($metric === AlertMetric::LogPatternCount && ! $plan->has('monitoring.log_patterns')) {
                $validator->errors()->add('metric', __('Log pattern alerts come with Monitoring Pro and above.'));
            }
            if ($metric?->isSloBurnRate() && ! $validator->errors()->hasAny(['environment_id', 'service_level_objective_id'])) {
                $objective = ServiceLevelObjective::query()->whereIn('environment_id', Environment::query()->where('project_id', $this->project()->id)->select('id'))->whereKey((int) $this->input('service_level_objective_id'))->first();
                if ($objective === null || ! $objective->enabled) {
                    $validator->errors()->add('service_level_objective_id', __('Choose an enabled SLO from the selected environment.'));
                } elseif ((string) $this->input('environment_id') !== $objective->environment_id) {
                    $validator->errors()->add('service_level_objective_id', __('The SLO must belong to the selected environment.'));
                }
            }
            if ($metric === AlertMetric::LogPatternCount && ! $validator->errors()->has('match_text')
                && $redactor->redact(['match_text' => $this->input('match_text')])['match_text'] !== $this->input('match_text')) {
                $validator->errors()->add('match_text', __('Use a log pattern without secrets or sensitive values.'));
            }
            if (in_array($metric, [AlertMetric::NumericMetric, AlertMetric::MetricAnomaly], true) && ! $validator->errors()->hasAny(['environment_id', 'metric_series_id'])) {
                $series = MetricSeries::query()->where('environment_id', $this->input('environment_id'))->whereKey((int) $this->input('metric_series_id'))->first();
                if ($series === null) {
                    $validator->errors()->add('metric_series_id', __('Choose a metric series from the selected environment.'));
                } elseif ($metric === AlertMetric::MetricAnomaly && ! ($series->kind === 'gauge' || ($series->kind === 'sum' && $series->temporality === 'delta'))) {
                    $validator->errors()->add('metric_series_id', __('Anomaly alerts require a gauge or delta sum metric series.'));
                } elseif ($metric === AlertMetric::NumericMetric && (! in_array($series->kind, ['gauge', 'sum'], true) || ($this->input('aggregation') === 'rate' && ! $series->supportsRate()))) {
                    $validator->errors()->add('aggregation', __('This series does not support the selected numeric calculation.'));
                }
            }
            $rule = $this->rule();
            if ($rule !== null && ! $validator->errors()->has('environment_id') && (string) $this->input('environment_id') !== $rule->environment_id) {
                $validator->errors()->add('environment_id', __('The environment can’t be changed. Create a separate rule.'));
            }
            if (! $validator->errors()->has('service') && $redactor->redact(['service' => $this->input('service')])['service'] !== $this->input('service')) {
                $validator->errors()->add('service', __('Use a service label without secrets.'));
            }
        }];
    }

    /**
     * The project in the URL.
     */
    public function project(): Project
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project, 404);

        return $project;
    }

    /** The rule being changed, or null when creating one. */
    public function rule(): ?AlertRule
    {
        $rule = $this->route('rule');

        return $rule instanceof AlertRule ? $rule : null;
    }
}
