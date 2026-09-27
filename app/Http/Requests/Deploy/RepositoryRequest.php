<?php

declare(strict_types=1);

namespace App\Http\Requests\Deploy;

use Illuminate\Foundation\Http\FormRequest;

final class RepositoryRequest extends FormRequest
{
    /**
     * A repository's settings. The URL must look like `host/owner/repo`, the branch must be a valid Git ref name, and
     * the deployment root must be a relative path that doesn't climb out with `..`.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'provider_id' => ['required', 'integer'],
            'url' => ['required', 'string', 'max:255', 'regex:#\A[a-z0-9.-]+/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*\z#'],
            'branch' => ['required', 'string', 'max:255', 'regex:#\A(?!/)(?!.*//)(?!.*\.\.)[A-Za-z0-9._/-]+(?<![/.])\z#'],
            'website_id' => ['required', 'integer'],
            'environment_id' => ['nullable', 'string', 'max:26'],
            'deployment_root' => ['nullable', 'string', 'max:512', 'regex:#\A[A-Za-z0-9._/-]+\z#', 'not_regex:#(\A|/)\.\.(/|\z)#'],
            'build_commands' => ['nullable', 'string', 'max:10000'],
            'post_deployment_commands' => ['nullable', 'string', 'max:10000'],
            'auto_deploy_include_paths' => ['nullable', 'string', 'max:4000'],
            'auto_deploy_exclude_paths' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * The validated settings, with the automatic-deploy path filters split into lists, one pattern per line.
     *
     * @return array{name: string, provider_id: int|string, url: string, branch: string, website_id: int|string, environment_id?: string|null, deployment_root?: string|null, build_commands?: string|null, post_deployment_commands?: string|null, auto_deploy_include_paths?: list<string>, auto_deploy_exclude_paths?: list<string>}
     */
    public function repository(): array
    {
        /** @var array{name: string, provider_id: int|string, url: string, branch: string, website_id: int|string, environment_id?: string|null, deployment_root?: string|null, build_commands?: string|null, post_deployment_commands?: string|null, auto_deploy_include_paths?: string|null, auto_deploy_exclude_paths?: string|null} $data */
        $data = $this->validated();
        $paths = fn (?string $value): array => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value) ?: []), fn (string $path): bool => $path !== ''));

        return [...$data, 'auto_deploy_include_paths' => $paths($data['auto_deploy_include_paths'] ?? null), 'auto_deploy_exclude_paths' => $paths($data['auto_deploy_exclude_paths'] ?? null)];
    }

    /**
     * Normalises the repository URL (`https://`, `git@host:`, a `.git` suffix and trailing slashes all come off,
     * lowercased) and trims slashes from the deployment root before validation.
     */
    protected function prepareForValidation(): void
    {
        $url = strtolower(trim((string) $this->input('url')));
        $url = preg_replace(['#\Ahttps?://#', '#\Agit@([^:]+):#', '#\.git\z#', '#/+\z#'], ['', '$1/', '', ''], $url) ?? $url;
        $this->merge(['url' => $url, 'deployment_root' => trim((string) $this->input('deployment_root'), ' /') ?: null]);
    }
}
