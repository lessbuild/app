<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\Dashboard;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveDashboardRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $workspace = $currentWorkspace->get();
        $dashboard = $this->route('monitor.dashboard');
        abort_unless(! $dashboard instanceof Dashboard || $dashboard->workspace_id === $workspace->id, 404);
        Gate::authorize('update', $workspace);

        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'description' => ['nullable', 'string', 'max:1000'],
            'range' => ['required', 'string', Rule::in(array_keys(Dashboard::RANGES))],
            'widgets' => ['required', 'array', 'min:1', 'max:6'],
            'widgets.*' => ['required', 'string', 'distinct', Rule::in(array_keys(Dashboard::WIDGET_TYPES))],
        ];
    }
}
