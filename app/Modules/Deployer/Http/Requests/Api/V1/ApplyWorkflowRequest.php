<?php

namespace App\Modules\Deployer\Http\Requests\Api\V1;

use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Services\ControlPlaneAccess;
use Illuminate\Foundation\Http\FormRequest;

class ApplyWorkflowRequest extends FormRequest
{
    /**
     * Preserve API access, project authorization, and validation ordering for workflow application.
     */
    public function authorize(): bool
    {
        app(ControlPlaneAccess::class)->enforce($this, 'manage');
        $project = $this->route('project');

        return $project instanceof Project
            && ($this->user()?->can('update', $project) ?? false);
    }

    /**
     * Preserve the API workflow field and 50 KB request boundary.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'workflow' => ['required', 'string', 'max:50000'],
        ];
    }

    /**
     * Return the validated YAML explicitly to the workflow application service.
     */
    public function workflow(): string
    {
        return (string) $this->validated('workflow');
    }
}
