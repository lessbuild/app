<?php

namespace App\Http\Requests;

use App\Models\Provider;
use App\Models\Server;
use App\Models\Website;
use App\Rules\GitHubRepositoryUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider_id' => [
                'required',
                'integer',
                Rule::exists('providers', 'id')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->id)
                    ->where('provider', Provider::TYPE_GITHUB)
                    ->whereNull('deleted_at')),
            ],
            'website_id' => [
                'required',
                'integer',
                Rule::exists('websites', 'id')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->id)
                    ->where('provisioning_status', Website::STATUS_ACTIVE)
                    ->whereExists(fn ($servers) => $servers
                        ->selectRaw('1')
                        ->from('servers')
                        ->whereColumn('servers.id', 'websites.server_id')
                        ->where('servers.provisioning_status', Server::STATUS_ACTIVE))),
            ],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:255', new GitHubRepositoryUrl],
            'branch' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?![-\/]|.*(?:\/\.|\.\.|\/\/|@\{|[~^:?*\[\\\\]))(?!.*[\/.]$)[A-Za-z0-9._\/-]+$/',
            ],
            'description' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'website_id.exists' => __('Select an active website.'),
            'branch.regex' => __('Enter a valid Git branch name.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $url = trim((string) $this->input('url'));
        $url = preg_replace('#^(?:https?://|ssh://git@)#i', '', $url) ?? $url;
        $url = preg_replace('#^git@github\.com:#i', 'github.com/', $url) ?? $url;
        $url = preg_replace('#^github\.com/#i', 'github.com/', $url) ?? $url;
        $url = rtrim($url, '/');
        $url = preg_replace('/\.git$/i', '', $url) ?? $url;

        $this->merge([
            'url' => $url.'.git',
            'branch' => trim((string) $this->input('branch', 'main')),
        ]);
    }
}
