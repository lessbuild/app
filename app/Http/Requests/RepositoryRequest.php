<?php

namespace App\Http\Requests;

use App\Models\Provider;
use App\Models\Server;
use App\Models\Website;
use App\Rules\GitBranch;
use App\Rules\RepositoryPathPattern;
use App\Rules\SourceRepositoryUrl;
use App\Support\RepositoryPath;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepositoryRequest extends FormRequest
{
    /**
     * Allow repository submissions only when the user has deployment permission in the current workspace.
     */
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
                Rule::exists('providers', 'id')->where(fn (Builder $query): Builder => $query
                    ->where('organization_id', $this->user()->current_organization_id)
                    ->whereIn('provider', Provider::SOURCE_CONTROL_TYPES)
                    ->whereNull('deleted_at')),
            ],
            'website_id' => [
                'required',
                'integer',
                Rule::exists('websites', 'id')->where(fn (Builder $query): Builder => $query
                    ->where('organization_id', $this->user()->current_organization_id)
                    ->where('provisioning_status', Website::STATUS_ACTIVE)
                    ->whereExists(fn (Builder $servers): Builder => $servers
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
            'auto_deploy_include_paths' => ['nullable', 'array', 'max:50'],
            'auto_deploy_include_paths.*' => ['string', 'max:255', new RepositoryPathPattern],
            'auto_deploy_exclude_paths' => ['nullable', 'array', 'max:50'],
            'auto_deploy_exclude_paths.*' => ['string', 'max:255', new RepositoryPathPattern],
            'build_commands' => ['nullable', 'string', 'max:10000'],
            'post_deployment_commands' => ['nullable', 'string', 'max:10000'],
            'description' => ['required', 'string'],
        ];
    }

    /**
     * Explain that a repository must attach to an active website when placement validation fails.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'website_id.exists' => __('Select an active website.'),
        ];
    }

    /**
     * Normalize the repository URL, branch, path filters and commands while retaining omitted values.
     */
    protected function prepareForValidation(): void
    {
        $repository = $this->route('repository');
        $buildCommands = $this->input('build_commands', $repository?->build_commands);
        $postDeploymentCommands = $this->input(
            'post_deployment_commands',
            $repository?->post_deployment_commands,
        );
        $includePaths = $this->input('auto_deploy_include_paths', $repository?->auto_deploy_include_paths);
        $excludePaths = $this->input('auto_deploy_exclude_paths', $repository?->auto_deploy_exclude_paths);

        $this->merge([
            'url' => SourceRepositoryUrl::normalize((string) $this->input('url')),
            'branch' => trim((string) $this->input('branch', 'main')),
            'build_commands' => is_string($buildCommands) && trim($buildCommands) === ''
                ? null
                : $buildCommands,
            'post_deployment_commands' => is_string($postDeploymentCommands) && trim($postDeploymentCommands) === ''
                ? null
                : $postDeploymentCommands,
            'auto_deploy_include_paths' => $this->pathPatterns($includePaths),
            'auto_deploy_exclude_paths' => $this->pathPatterns($excludePaths),
        ]);
    }

    /**
     * Convert the newline-oriented form fields into validated path-pattern arrays.
     *
     * @param  mixed  $value  Existing array, submitted textarea, null or invalid input.
     * @return mixed Normalized array/null or the original invalid value for validation to reject.
     */
    private function pathPatterns(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = preg_split('/\R/u', $value) ?: [];
        }
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            return $value;
        }

        $patterns = [];
        foreach ($value as $pattern) {
            if (is_string($pattern) && trim($pattern) === '') {
                continue;
            }

            $patterns[] = RepositoryPath::normalizePattern($pattern) ?? $pattern;
        }

        return $patterns === [] ? null : $patterns;
    }
}
