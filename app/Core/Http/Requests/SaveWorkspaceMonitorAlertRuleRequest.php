<?php

namespace App\Core\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveWorkspaceMonitorAlertRuleRequest extends WorkspaceMonitorAdministrationRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $editing = filled($this->input('rule_reference'));
        $metric = $this->input('metric');

        return [
            'rule_reference' => ['nullable', 'string', 'max:4096'], 'version' => [$editing ? 'required' : 'nullable', 'integer', 'min:0'],
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'environment_reference' => ['required', 'string', 'max:4096'],
            'metric' => ['required', Rule::in(['request_error_rate', 'request_duration', 'exception_count', 'error_log_count', 'log_pattern_count', 'telemetry_volume', 'telemetry_freshness', 'slo_burn_rate', 'numeric_metric', 'metric_anomaly'])],
            // Metric-specific limits belong to the native provider, checked under its mutation lock.
            'threshold' => ['required', 'numeric', 'decimal:0,3', 'between:-1000000000000000,1000000000000000'],
            'window_minutes' => ['required', 'integer', Rule::in([1, 5, 15, 30, 60])],
            'minimum_samples' => ['required', 'integer', 'between:1,1000000'],
            'trigger_checks' => ['required', 'integer', 'between:1,10'], 'recovery_checks' => ['required', 'integer', 'between:1,10'],
            'enabled' => ['required', 'boolean'], 'service' => ['nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'match_text' => [$metric === 'log_pattern_count' ? 'required' : 'nullable', 'string', 'max:120'],
            'metric_series_reference' => [in_array($metric, ['numeric_metric', 'metric_anomaly'], true) ? 'required' : 'nullable', 'string', 'max:4096'],
            'objective_reference' => [$metric === 'slo_burn_rate' ? 'required' : 'nullable', 'string', 'max:4096'],
            'aggregation' => [$metric === 'numeric_metric' ? 'required' : 'nullable', Rule::in(['last', 'mean', 'min', 'max', 'rate'])],
            'comparison' => [$metric === 'numeric_metric' ? 'required' : 'nullable', Rule::in(['gte', 'lte'])],
            'freshness_seconds' => [$metric === 'numeric_metric' ? 'required' : 'nullable', 'integer', 'between:30,3600'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $response = redirect()->back()->withErrors($validator)->withInput($this->except(['service', 'match_text']));

        throw new ValidationException($validator, $response);
    }
}
