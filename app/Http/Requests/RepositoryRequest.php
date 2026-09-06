<?php

namespace App\Http\Requests;

use App\Models\Provider;
use App\Models\Server;
use App\Models\Website;
use App\Rules\GitBranch;
use App\Rules\SourceRepositoryUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->currentOrganization?->permits($this->user(), 'deploy') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $provider = $this->user()->workspaceProviders()
            ->whereKey($this->input('provider_id'))
            ->whereNull('deleted_at')
            ->first();

        return [
            'provider_id' => [
                'required',
                'integer',
                Rule::exists('providers', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $this->user()->current_organization_id)
                    ->whereIn('provider', Provider::SOURCE_CONTROL_TYPES)
                    ->whereNull('deleted_at')),
            ],
            'website_id' => [
                'required',
                'integer',
                Rule::exists('websites', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $this->user()->current_organization_id)
                    ->where('provisioning_status', Website::STATUS_ACTIVE)
                    ->whereExists(fn ($servers) => $servers
                        ->selectRaw('1')
                        ->from('servers')
                        ->whereColumn('servers.id', 'websites.server_id')
                        ->where('servers.provisioning_status', Server::STATUS_ACTIVE))),
            ],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:255', new SourceRepositoryUrl($provider?->repositoryHost())],
            'branch' => [
                'required',
                'string',
                'max:255',
                new GitBranch,
            ],
            'build_commands' => ['nullable', 'string', 'max:10000'],
            'post_deployment_commands' => ['nullable', 'string', 'max:10000'],
            'description' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'website_id.exists' => __('Select an active website.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $repository = $this->route('repository');
        $buildCommands = $this->input('build_commands', $repository?->build_commands);
        $postDeploymentCommands = $this->input(
            'post_deployment_commands',
            $repository?->post_deployment_commands,
        );

        $this->merge([
            'url' => SourceRepositoryUrl::normalize((string) $this->input('url')),
            'branch' => trim((string) $this->input('branch', 'main')),
            'build_commands' => is_string($buildCommands) && trim($buildCommands) === ''
                ? null
                : $buildCommands,
            'post_deployment_commands' => is_string($postDeploymentCommands) && trim($postDeploymentCommands) === ''
                ? null
                : $postDeploymentCommands,
        ]);
    }
}
