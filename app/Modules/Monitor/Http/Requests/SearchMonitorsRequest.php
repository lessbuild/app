<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SearchMonitorsRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $workspace): bool
    {
        if ($monitor = $this->route('monitor')) {
            abort_unless($monitor instanceof Monitor && Monitor::withTrashed()->forWorkspace($workspace->get())->whereKey($monitor->id)->exists(), 404);
            Gate::authorize($this->routeIs('monitor.monitors.edit') ? 'update' : 'view', $monitor);
        } elseif ($this->routeIs('monitor.monitors.create')) {
            Gate::authorize('create', [Monitor::class, $workspace->get()]);
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
            'check_type' => ['nullable', 'string', Rule::in(array_keys(SaveMonitorRequest::TYPES))],
            'state' => ['nullable', 'string', Rule::in(['all', 'enabled', 'paused', 'archived'])],
            'page' => ['nullable', 'integer', 'between:1,100000'],
            'incidents_page' => ['nullable', 'integer', 'between:1,100000'],
            'runs_page' => ['nullable', 'integer', 'between:1,100000'],
            'snapshots_page' => ['nullable', 'integer', 'between:1,100000'],
            'workers_page' => ['nullable', 'integer', 'between:1,100000'],
        ];
    }
}
