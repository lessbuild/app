<?php

namespace App\Core\Http\Requests;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectHandoverManifestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('platform');
        $workspace = $this->route('workspace');

        return $user instanceof PlatformUser
            && $workspace instanceof Workspace
            && app(WorkspaceProjectAccess::class)->canManageWorkspace($user, $workspace);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'manifest' => ['required', 'file', 'mimes:json', 'max:2048'],
        ];
    }
}
