<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompareApplicationConfigurationRequest extends FormRequest
{
    /**
     * Allow recorded environment comparisons only to workspace managers of the bound project.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && ($this->user()?->can('manageConfiguration', $project) ?? false);
    }

    /**
     * Validate distinct environment IDs against the bound project without exposing foreign project records.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $projectId = $project instanceof Project ? $project->id : 0;
        $environment = fn () => Rule::exists('environments', 'id')->where(fn ($query) => $query->where('project_id', $projectId));

        return [
            'from_environment_id' => ['required', 'integer', 'different:to_environment_id', $environment()],
            'to_environment_id' => ['required', 'integer', 'different:from_environment_id', $environment()],
        ];
    }

    /**
     * Return the validated source environment ID.
     */
    public function fromEnvironmentId(): int
    {
        return (int) $this->validated('from_environment_id');
    }

    /**
     * Return the validated target environment ID.
     */
    public function toEnvironmentId(): int
    {
        return (int) $this->validated('to_environment_id');
    }
}
