<?php

declare(strict_types=1);

namespace App\Http\Requests\Infrastructure;

use App\Rules\Hostname;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ImportWebsiteRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'server_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'url' => ['required', 'string', 'max:255', new Hostname, Rule::unique('websites', 'url'), Rule::unique('website_domains', 'hostname')],
            'deployment_slug' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9-]{0,31}\z/'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array{server_id: int, name: string, url: string, deployment_slug: string, description: string|null} */
    public function website(): array
    {
        /** @var array{server_id: int|string, name: string, url: string, deployment_slug: string, description?: string|null} $data */
        $data = $this->validated();

        return ['server_id' => (int) $data['server_id'], 'name' => $data['name'], 'url' => $data['url'], 'deployment_slug' => $data['deployment_slug'], 'description' => $data['description'] ?? null];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['url' => strtolower(rtrim(preg_replace('#^https?://#i', '', trim((string) $this->input('url'))) ?? '', '/'))]);
    }
}
