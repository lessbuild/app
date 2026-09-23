<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SearchAlertsRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        if ($rule = $this->route('alertRule')) {
            abort_unless($rule instanceof AlertRule && AlertRule::withTrashed()->forWorkspace($currentWorkspace->get())->whereKey($rule->id)->exists(), 404);
            Gate::authorize($this->routeIs('monitor.alerts.edit') ? 'update' : 'view', $rule);
        } elseif ($this->routeIs('monitor.alerts.create')) {
            Gate::authorize('create', [AlertRule::class, $currentWorkspace->get()]);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'state' => ['nullable', 'string', Rule::in(['all', 'enabled', 'paused', 'archived'])],
            'page' => ['nullable', 'integer', 'between:1,100000'],
            'series' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
