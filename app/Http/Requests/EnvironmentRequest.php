<?php

namespace App\Http\Requests;

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnvironmentRequest extends FormRequest
{
    /** Keep the existing project/environment policy denial ahead of validation; the controller repeats the explicit boundary. */
    public function authorize(): bool
    {
        $resource = $this->route('project') ?? $this->route('environment');

        return ($resource instanceof Project || $resource instanceof Environment)
            && ($this->user()?->can('update', $resource) ?? false);
    }

    /**
     * Validate runtime, placement, scaling and deployment-protection fields against the project's workspace.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        if (! $project instanceof Project) {
            $project = $this->route('environment')?->project;
        }
        $organizationId = $project?->organization_id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(Environment::TYPES)],
            'branch' => ['required', 'string', 'max:255'],
            'runtime_type' => ['required', Rule::in(Environment::RUNTIME_TYPES)],
            'runtime_version' => ['nullable', 'string', 'max:20', 'regex:/\A[0-9]+(?:\.[0-9]+){0,2}\z/'],
            'build_command' => ['nullable', 'string', 'max:2000'],
            'start_command' => ['nullable', 'string', 'max:2000', 'required_if:runtime_type,node,python'],
            'container_port' => ['nullable', 'integer', 'between:1,65535', 'required_if:runtime_type,node,python,docker'],
            'dockerfile_path' => ['nullable', 'string', 'max:255', 'regex:/\A(?!\/)(?!.*\.\.)(?:[A-Za-z0-9_.-]+\/)*[A-Za-z0-9_.-]+\z/', 'required_if:runtime_type,docker'],
            'server_id' => ['nullable', Rule::exists('servers', 'id')->where('organization_id', $organizationId)],
            'website_id' => ['nullable', Rule::exists('websites', 'id')->where('organization_id', $organizationId)],
            'is_protected' => ['required', 'boolean'],
            'requires_deployment_approval' => ['required', 'boolean'],
            'minimum_replicas' => ['required', 'integer', 'between:1,20'],
            'maximum_replicas' => ['required', 'integer', 'between:1,20', 'gte:minimum_replicas'],
            'hibernate_after_minutes' => ['nullable', 'integer', Rule::in([5, 15, 30, 60, 120, 1440])],
        ];
    }

    /** Retain the same defaults for new environments and omitted update fields as the previous controller boundary. */
    protected function prepareForValidation(): void
    {
        $environment = $this->route('environment');
        if ($environment instanceof Environment) {
            $this->mergeIfMissing([
                'minimum_replicas' => $environment->minimum_replicas,
                'maximum_replicas' => $environment->maximum_replicas,
                'hibernate_after_minutes' => $environment->hibernate_after_minutes,
                'runtime_type' => $environment->runtime_type ?: 'php',
                'runtime_version' => $environment->runtime_version,
                'build_command' => $environment->build_command,
                'start_command' => $environment->start_command,
                'container_port' => $environment->container_port,
                'dockerfile_path' => $environment->dockerfile_path,
            ]);

            return;
        }

        $this->mergeIfMissing([
            'minimum_replicas' => 1,
            'maximum_replicas' => 1,
            'runtime_type' => 'php',
            'runtime_version' => null,
            'build_command' => null,
            'start_command' => null,
            'container_port' => null,
            'dockerfile_path' => null,
        ]);
    }
}
