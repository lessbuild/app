<?php

namespace App\Core\Http\Requests;

use App\Modules\Monitor\Models\Application;
use Illuminate\Validation\Rule;

final class SaveWorkspaceMonitorConfigurationRequest extends WorkspaceMonitorAdministrationRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'updateApplication' => [
                'application_reference' => ['required', 'string', 'max:4096'],
                'name' => ['required', 'string', 'max:120'],
                'framework' => ['required', 'string', 'max:80'],
                'framework_version' => ['nullable', 'string', 'max:40'],
                'accent' => ['required', Rule::in(Application::ACCENTS)],
            ],
            'updateEnvironment' => [
                'environment_reference' => ['required', 'string', 'max:4096'],
                'name' => ['required', 'string', 'max:120'],
                'slug' => ['required', 'string', 'max:80', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
                'status' => ['required', Rule::in(['active', 'paused'])],
            ],
            'updateMonitor' => [
                'monitor_reference' => ['required', 'string', 'max:4096'],
                'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
                'enabled' => ['required', 'boolean'],
                'version' => ['required', 'integer', 'min:0'],
                'interval_minutes' => ['sometimes', 'integer', Rule::in([1, 5, 15, 30, 60])],
                'timeout_seconds' => ['sometimes', 'integer', 'between:1,20'],
                'trigger_checks' => ['sometimes', 'integer', 'between:1,10'],
                'recovery_checks' => ['sometimes', 'integer', 'between:1,10'],
            ],
            default => [],
        };
    }
}
