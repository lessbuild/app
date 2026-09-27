<?php

declare(strict_types=1);

namespace App\Http\Requests\Infrastructure;

use App\Rules\Hostname;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class WebsiteDomainRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'hostname' => ['required', 'string', 'max:255', new Hostname, Rule::unique('website_domains', 'hostname'), Rule::unique('websites', 'url')],
            'type' => ['required', Rule::in(['alias', 'redirect'])],
            'redirect_url' => ['nullable', 'required_if:type,redirect', 'url:https,http', 'max:2048'],
            'dns_provider_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{hostname: string, type: string, redirect_url: string|null, dns_provider_id: int|null} */
    public function domain(): array
    {
        /** @var array{hostname: string, type: string, redirect_url?: string|null, dns_provider_id?: int|string|null} $data */
        $data = $this->validated();

        return ['hostname' => $data['hostname'], 'type' => $data['type'], 'redirect_url' => $data['redirect_url'] ?? null, 'dns_provider_id' => isset($data['dns_provider_id']) ? (int) $data['dns_provider_id'] : null];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['hostname' => strtolower(rtrim(preg_replace('#^https?://#i', '', trim((string) $this->input('hostname'))) ?? '', '/'))]);
    }
}
