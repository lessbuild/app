<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Rules\Hostname;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProjectTemplateRequest extends FormRequest
{
    /**
     * Get the rules for a project made from a template: the template, the project's name, the app server and domain
     * for its website, and the repository (address as shown by the Git host, and branch).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template' => ['required', Rule::in(array_keys((array) config('templates')))],
            'name' => ['required', 'string', 'max:100'],
            'server_id' => ['required', 'integer', 'min:1'],
            'domain' => ['required', 'string', 'max:255', new Hostname, Rule::unique('websites', 'url'), Rule::unique('website_domains', 'hostname')],
            'provider_id' => ['required', 'integer', 'min:1'],
            'repository_url' => ['required', 'string', 'max:255', 'regex:#\A[a-z0-9.-]+/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*\z#'],
            'branch' => ['required', 'string', 'max:255', 'regex:#\A(?!/)(?!.*//)(?!.*\.\.)[A-Za-z0-9._/-]+(?<![/.])\z#'],
        ];
    }

    /**
     * Get the validated details as the action takes them.
     *
     * @return array{name: string, server_id: int, domain: string, provider_id: int, repository_url: string, branch: string}
     */
    public function details(): array
    {
        return [
            'name' => $this->string('name')->trim()->toString(), 'server_id' => $this->integer('server_id'), 'domain' => $this->string('domain')->toString(),
            'provider_id' => $this->integer('provider_id'), 'repository_url' => $this->string('repository_url')->toString(), 'branch' => $this->string('branch')->toString(),
        ];
    }

    /**
     * Normalise the repository address the way repository forms do (no scheme, no .git, lower case) and the domain.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $url = strtolower(trim((string) $this->input('repository_url')));
        $url = preg_replace(['#\Ahttps?://#', '#\Agit@([^:]+):#', '#\.git\z#', '#/+\z#'], ['', '$1/', '', ''], $url) ?? $url;
        $this->merge(['repository_url' => $url, 'domain' => strtolower(trim((string) $this->input('domain'))), 'branch' => trim((string) $this->input('branch', 'main')) ?: 'main']);
    }
}
