<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationMemberRequest extends FormRequest
{
    /**
     * Authorize member-role validation through the current workspace management policy.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $user?->currentOrganization;

        return $organization instanceof Organization
            && ($user?->can('manageMembers', $organization) ?? false);
    }

    /**
     * Validate the replacement workspace role.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(Organization::ROLES)],
        ];
    }

    /**
     * Return the validated replacement role.
     */
    public function role(): string
    {
        return $this->validated()['role'];
    }
}
