<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteOrganizationRequest extends FormRequest
{
    /** Keep workspace deletion validation failures in the existing named session error bag. */
    protected $errorBag = 'deleteWorkspace';

    /**
     * Authorize deletion only for the owner of the currently selected workspace.
     */
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization
            && ($this->user()?->can('delete', $organization) ?? false);
    }

    /**
     * Validate the exact workspace confirmation and the authentication challenges required by this account.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');
        $user = $this->user();
        $rules = [
            'confirmation' => ['required', Rule::in([$organization->name])],
        ];
        if ($user?->hasLocalPassword()) {
            $rules['current_password'] = ['required', 'current_password'];
        }
        if ($user?->twoFactorEnabled()) {
            $rules['code'] = ['required', 'string', 'max:64'];
        }

        return $rules;
    }
}
