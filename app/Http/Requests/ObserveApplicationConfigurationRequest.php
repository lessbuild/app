<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ObserveApplicationConfigurationRequest extends FormRequest
{
    /**
     * Allow provider observation only to workspace managers of the bound project.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && ($this->user()?->can('manageConfiguration', $project) ?? false);
    }

    /**
     * Validate the selected environment against the bound project before any provider request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $projectId = $project instanceof Project ? $project->id : 0;

        return [
            'environment_id' => [
                'required',
                'integer',
                Rule::exists('environments', 'id')->where(fn ($query) => $query->where('project_id', $projectId)),
            ],
        ];
    }

    /**
     * Return the validated environment identifier.
     */
    public function environmentId(): int
    {
        return (int) $this->validated('environment_id');
    }
}
