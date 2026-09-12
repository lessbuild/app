<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationNotificationPreferencesRequest extends FormRequest
{
    /**
     * Authorize workspace notification settings through the manager policy before validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $user?->currentOrganization;

        return $organization instanceof Organization
            && ($user?->can('manageSettings', $organization) ?? false);
    }

    /**
     * Validate notification categories and recovery preferences.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'categories' => ['nullable', 'array'],
            'categories.*' => ['required', Rule::in(['website', 'server', 'deployment', 'provider', 'security', 'recipe']), 'distinct'],
            'recoveries' => ['required', 'boolean'],
        ];
    }
}
