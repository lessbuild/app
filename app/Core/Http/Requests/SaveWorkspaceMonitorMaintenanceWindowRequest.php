<?php

namespace App\Core\Http\Requests;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;

final class SaveWorkspaceMonitorMaintenanceWindowRequest extends WorkspaceMonitorAdministrationRequest
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

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $editing = $this->isMethod('PATCH');

        return [
            'window_reference' => $editing ? ['required', 'string', 'max:4096'] : ['prohibited'],
            'version' => $editing ? ['required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/'] : ['prohibited'],
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\\TH:i', 'after:starts_at'],
        ];
    }
}
