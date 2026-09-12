<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class ApplyWorkflowRequest extends FormRequest
{
    /**
     * Preserve the project update policy before workflow validation.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && ($this->user()?->can('update', $project) ?? false);
    }

    /**
     * Preserve the web workflow field and 50 KB request boundary.
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
