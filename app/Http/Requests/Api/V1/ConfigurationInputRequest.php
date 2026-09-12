<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class ConfigurationInputRequest extends FormRequest
{
    /**
     * Allow API configuration planning and review only for managers of the bound project.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && ($this->user()?->can('manageConfiguration', $project) ?? false);
    }

    /**
     * Preserve the API document and array-binding contract, including empty bindings for removal-only documents.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', 'string', 'max:50000'],
            'bindings' => ['present', 'array:placements,secrets,repositories'],
        ];
    }

    /**
     * Return the validated document explicitly.
     */
    public function document(): string
    {
        return (string) $this->validated('document');
    }

    /**
     * Return the validated logical binding arrays explicitly.
     *
     * @return array<string, mixed>
     */
    public function bindings(): array
    {
        return $this->validated('bindings');
    }
}
