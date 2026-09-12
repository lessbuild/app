<?php

namespace App\Http\Requests;

use App\Models\OrganizationInvitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class AcceptOrganizationInvitationRequest extends FormRequest
{
    /**
     * Preserve the existing 403 ordering for invalid token, expiry, and invited-email identity.
     */
    public function authorize(): bool
    {
        $invitation = $this->route('invitation');
        $user = $this->user();
        if (! $invitation instanceof OrganizationInvitation || $user === null) {
            return false;
        }

        return $invitation->isUsable()
            && hash_equals($invitation->token_hash, hash('sha256', (string) $this->query('token')))
            && Str::lower($user->email) === Str::lower($invitation->email);
    }

    /**
     * Validate the token query input after its access checks have passed.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }

    /**
     * Return the validated invitation token.
     */
    public function token(): string
    {
        return $this->validated()['token'];
    }
}
