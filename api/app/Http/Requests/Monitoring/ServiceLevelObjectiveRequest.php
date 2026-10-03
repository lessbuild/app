<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Models\Environment;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Authorisation happens in SaveServiceLevelObjective. */
final class ServiceLevelObjectiveRequest extends FormRequest
{
    /**
     * Get the validation rules for an SLO's settings. Latency SLOs need a threshold and availability SLOs a status
     * range; the environment must be the project's.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project, 404);
        $environmentIds = Environment::query()->where('project_id', $project->id)->select('id');
        $indicator = is_string($this->input('indicator')) ? $this->input('indicator') : null;

        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'environment_id' => ['required', 'string', Rule::exists('environments', 'id')->where(fn (Builder $query): Builder => $query->whereIn('id', $environmentIds))],
            'indicator' => ['required', Rule::in(['availability', 'latency'])],
            'service' => ['nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'route' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'target' => ['required', 'numeric', 'decimal:1,3', 'between:0.001,99.999'],
            'window_days' => ['required', 'integer', Rule::in([7, 30])],
            'latency_threshold_ms' => [Rule::excludeIf($indicator !== 'latency'), 'required', 'numeric', 'gt:0', 'max:600000'],
            'status_min' => [Rule::excludeIf($indicator !== 'availability'), 'required', 'integer', 'between:100,599'],
            'status_max' => [Rule::excludeIf($indicator !== 'availability'), 'required', 'integer', 'between:100,599', 'gte:status_min'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * Refuse changing an existing SLO's environment.
     *
     * @return array<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $objective = $this->route('objective');
            if ($objective instanceof ServiceLevelObjective && ! $validator->errors()->has('environment_id')
                && (string) $this->input('environment_id') !== $objective->environment_id) {
                $validator->errors()->add('environment_id', __('The environment can’t be changed. Create a separate objective.'));
            }
        }];
    }
}
