<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateOrganizationSecurityPolicyRequest extends FormRequest
{
    /**
     * Authorize workspace security settings through the manager policy before validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $user?->currentOrganization;

        return $organization instanceof Organization
            && ($user?->can('manageSettings', $organization) ?? false);
    }

    /**
     * Validate the structure of network, identity, authentication, timeout and SSO settings.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'allowed_ip_ranges' => ['nullable', 'string', 'max:5000'],
            'allowed_email_domains' => ['nullable', 'string', 'max:2000'],
            'require_two_factor' => ['required', 'boolean'],
            'session_idle_minutes' => ['nullable', 'integer', Rule::in([15, 30, 60, 240, 720, 1440])],
            'sso_issuer' => ['nullable', 'url:https', 'max:1000'],
            'sso_client_id' => ['nullable', 'string', 'max:500'],
            'sso_client_secret' => ['nullable', 'string', 'max:2000'],
            'sso_enforced' => ['required', 'boolean'],
        ];
    }

    /**
     * Normalize case-insensitive email-domain input before validation and persistence.
     */
    protected function prepareForValidation(): void
    {
        $domains = $this->input('allowed_email_domains');

        $this->merge([
            'allowed_email_domains' => is_string($domains) ? Str::lower($domains) : $domains,
        ]);
    }
}
