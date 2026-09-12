<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class EnterpriseSsoCallbackRequest extends FormRequest
{
    /**
     * Preserve entitlement enforcement before callback field validation.
     *
     * @throws ValidationException If the current workspace lacks SSO access.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $user?->currentOrganization;
        if (! $user instanceof User || ! $organization instanceof Organization) {
            return false;
        }

        app(Entitlements::class)->enforce($organization, 'sso');

        return true;
    }

    /**
     * Validate the authorization code and state before the remote exchange.
     *
     * @return array{code: list<string>, state: list<string>}
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:4000'],
            'state' => ['required', 'string', 'max:200'],
        ];
    }

    /** Return the validated authorization code. */
    public function code(): string
    {
        return (string) $this->validated('code');
    }

    /** Return the validated callback state. */
    public function state(): string
    {
        return (string) $this->validated('state');
    }
}
