<?php

namespace App\Core\Http\Requests;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Validation\Rule;

final class SaveWorkspaceMonitorServiceObjectiveRequest extends WorkspaceMonitorAdministrationRequest
{
    public function authorize(WorkspaceProjectAccess $access): bool
    {
        $user = $this->user('platform');
        $workspace = $this->route('workspace');

        return parent::authorize($access)
            && $user instanceof PlatformUser
            && $workspace instanceof Workspace
            && $access->canManageWorkspace($user, $workspace);
    }

    /** @return array<string, array<mixed>|string> */
    public function rules(): array
    {
        $editing = $this->isMethod('PATCH');
        $indicator = is_string($this->input('indicator')) ? $this->input('indicator') : null;

        return [
            'objective_reference' => $editing ? ['required', 'string', 'max:4096'] : ['prohibited'],
            'version' => $editing ? ['required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/'] : ['prohibited'],
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'environment_reference' => ['required', 'string', 'max:4096'],
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
}
