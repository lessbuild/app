<?php

namespace App\Http\Requests;

use App\Models\Environment;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DeploymentControlsRequest extends FormRequest
{
    /** Keep the existing environment policy denial ahead of validation; the controller repeats the explicit boundary. */
    public function authorize(): bool
    {
        $environment = $this->route('environment');

        return $environment instanceof Environment
            && ($this->user()?->can('update', $environment) ?? false);
    }

    /**
     * Validate deployment locks, maintenance windows, rollout strategy and rollback settings.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'deployment_locked' => ['required', 'boolean'],
            'deployment_lock_reason' => ['nullable', 'string', 'max:500'],
            'deployment_window_enabled' => ['required', 'boolean'],
            'deployment_window_days' => ['nullable', 'array', 'min:1'],
            'deployment_window_days.*' => ['integer', 'between:1,7', 'distinct'],
            'deployment_window_start' => ['nullable', 'date_format:H:i'],
            'deployment_window_end' => ['nullable', 'date_format:H:i'],
            'deployment_window_timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'deployment_strategy' => ['required', Rule::in(Environment::DEPLOYMENT_STRATEGIES)],
            'rolling_pause_seconds' => ['required', 'integer', Rule::in([0, 1, 2, 5, 10, 30])],
            'automatic_rollback' => ['required', 'boolean'],
        ];
    }

    /** Retain current control values when an update omits optional rollout settings. */
    protected function prepareForValidation(): void
    {
        $environment = $this->route('environment');
        if (! $environment instanceof Environment) {
            return;
        }

        $this->mergeIfMissing([
            'deployment_strategy' => $environment->deployment_strategy ?: 'blue_green',
            'rolling_pause_seconds' => $environment->rolling_pause_seconds ?? 2,
            'automatic_rollback' => false,
        ]);
    }

    /** Preserve the existing cross-field maintenance-window validation message and key. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $this->all();
            if ($data['deployment_window_enabled'] && (empty($data['deployment_window_days'])
                || empty($data['deployment_window_start']) || empty($data['deployment_window_end'])
                || empty($data['deployment_window_timezone']))) {
                $validator->errors()->add(
                    'deployment_window_days',
                    __('Choose days, start and end times, and a timezone for the window.'),
                );
            }
        }];
    }
}
