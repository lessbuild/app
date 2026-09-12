<?php

namespace App\Http\Requests;

use App\Models\Provider;
use App\Rules\Hostname;
use Illuminate\Validation\Rule;

class StoreWebsiteDomainRequest extends WebsiteDomainRequest
{
    /** Normalize the submitted hostname exactly as the previous controller did before validation. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'hostname' => strtolower(rtrim(trim((string) $this->input('hostname')), '.')),
        ]);
    }

    /**
     * Validate a hostname, alias/redirect type, redirect target, and optional workspace Cloudflare provider.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $website = $this->website();

        return [
            'hostname' => ['required', 'string', 'max:253', new Hostname, Rule::unique('website_domains', 'hostname')],
            'type' => ['required', Rule::in(['alias', 'redirect'])],
            'redirect_url' => ['nullable', 'required_if:type,redirect', 'url:https', 'max:500'],
            'dns_provider_id' => ['nullable', Rule::exists('providers', 'id')->where(fn ($query) => $query
                ->where('organization_id', $website->organization_id)
                ->where('provider', Provider::TYPE_CLOUDFLARE))],
        ];
    }
}
