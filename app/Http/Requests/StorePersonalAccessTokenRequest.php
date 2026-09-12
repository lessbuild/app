<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class StorePersonalAccessTokenRequest extends FormRequest
{
    /**
     * Preserve owner authorization and API entitlement denial before token-input validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User || ! $user->can('create', PersonalAccessToken::class)) {
            return false;
        }

        app(Entitlements::class)->enforce($user, 'api');

        return true;
    }

    /**
     * Preserve token name, allowed abilities, and expiry validation.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(['read', 'deploy', 'manage'])],
            'expires_in_days' => ['required', 'integer', Rule::in([30, 90, 180, 365])],
        ];
    }

    /**
     * Preserve the one-year default for omitted expiry while leaving explicit null invalid.
     */
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['expires_in_days' => 365]);
    }
}
