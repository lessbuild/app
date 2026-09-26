<?php

namespace App\Core\Http\Requests;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Foundation\Http\FormRequest;

abstract class WorkspaceMonitorAdministrationRequest extends FormRequest
{
    public function authorize(WorkspaceProjectAccess $access): bool
    {
        $user = $this->user('platform');
        $workspace = $this->route('workspace');
        if (! $user instanceof PlatformUser || ! $workspace instanceof Workspace) {
            return false;
        }
        $membership = $access->activeMembership($user, $workspace);

        return $membership !== null && $access->hasProductAccess($membership, 'monitor');
    }
}
