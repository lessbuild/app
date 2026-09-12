<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DisconnectSocialRequest extends FormRequest
{
    /** Keep social-account validation failures in the existing named session bag. */
    protected $errorBag = 'social';

    /** Social-account management is available only to the authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Require the existing local password only when disconnecting a connected provider from a local account.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $provider = (string) $this->route('provider');
        $user = $this->user();

        if ($user?->hasLocalPassword() && in_array($provider, $user->connectedSocialProviders(), true)) {
            return [
                'social_provider' => ['required', Rule::in([$provider])],
                'current_password' => ['required', 'current_password'],
            ];
        }

        return [];
    }

    /** Return the route-constrained provider key to the application operation. */
    public function provider(): string
    {
        return (string) $this->route('provider');
    }
}
