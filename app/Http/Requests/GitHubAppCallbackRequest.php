<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class GitHubAppCallbackRequest extends FormRequest
{
    /** Require current-workspace management before callback input is validated. */
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $user?->currentOrganization;

        return $user instanceof User
            && $organization instanceof Organization
            && $user->can('manage', $organization);
    }

    /**
     * Validate GitHub's installation callback fields without changing the existing accepted values.
     *
     * @return array{installation_id: list<string>, setup_action: list<string>, state: list<string>}
     */
    public function rules(): array
    {
        return [
            'installation_id' => ['required', 'integer', 'min:1'],
            'setup_action' => ['nullable', 'string', 'in:install,update'],
            'state' => ['required', 'string', 'size:64'],
        ];
    }

    /** Return the validated installation identifier without normalizing its persisted string form. */
    public function installationId(): string
    {
        return (string) $this->validated('installation_id');
    }

    /** Return the validated callback state. */
    public function state(): string
    {
        return (string) $this->validated('state');
    }
}
