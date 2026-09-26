<?php

namespace App\Core\Http\Requests;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;

final class ArchiveWorkspaceMonitorServiceObjectiveRequest extends WorkspaceMonitorAdministrationRequest
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
        return [
            'objective_reference' => ['required', 'string', 'max:4096'],
            'version' => ['required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/'],
            'confirm_archive' => ['required', 'accepted'],
        ];
    }
}
