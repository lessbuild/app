<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveServiceLevelObjectiveRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $workspace = $currentWorkspace->get();
        $objective = $this->route('serviceLevelObjective');
        if ($objective instanceof ServiceLevelObjective) {
            abort_unless(ServiceLevelObjective::forWorkspace($workspace)->whereKey($objective->id)->exists(), 404);
        }
        Gate::authorize('update', $workspace);

        return true;
    }

    /** @return array<string, array<mixed>|string> */
    public function rules(CurrentWorkspace $currentWorkspace): array
    {
        $environmentIds = Environment::forWorkspace($currentWorkspace->get())->select('id');
        $indicator = is_string($this->input('indicator')) ? $this->input('indicator') : null;

        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'environment_id' => ['required', 'integer', Rule::exists('monitor.environments', 'id')->where(fn (Builder $query): Builder => $query->whereIn('id', $environmentIds))],
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

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $objective = $this->route('serviceLevelObjective');
            if ($objective instanceof ServiceLevelObjective && ! $validator->errors()->has('environment_id')
                && (int) $this->input('environment_id') !== $objective->environment_id) {
                $validator->errors()->add('environment_id', 'The environment cannot be changed. Create a separate objective.');
            }
        }];
    }
}
