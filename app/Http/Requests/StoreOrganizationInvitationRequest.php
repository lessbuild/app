<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreOrganizationInvitationRequest extends FormRequest
{
    /**
     * Preserve manager and team-entitlement checks before invite input validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $user?->currentOrganization;
        if ($user === null || $organization === null || ! $user->can('invite', $organization)) {
            return false;
        }

        app(Entitlements::class)->enforce($organization, 'teams');

        return true;
    }

    /**
     * Validate the invited email and workspace role.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(Organization::ROLES)],
        ];
    }

    /**
     * Normalize the invitation email before validation and persistence.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        $this->merge([
            'email' => is_string($email) ? Str::lower($email) : $email,
        ]);
    }
}
