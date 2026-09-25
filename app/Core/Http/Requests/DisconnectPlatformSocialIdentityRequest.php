<?php

namespace App\Core\Http\Requests;

use App\Core\Models\PlatformUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DisconnectPlatformSocialIdentityRequest extends FormRequest
{
    protected $errorBag = 'social';

    public function authorize(): bool
    {
        $user = $this->user('platform');

        return $user instanceof PlatformUser && $user->status === 'active';
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $user = $this->user('platform');

        return [
            'social_provider' => ['required', 'string', Rule::in([(string) $this->route('provider')])],
            'current_password' => $user instanceof PlatformUser && $user->hasPassword()
                ? ['required', 'current_password:platform']
                : ['nullable', 'string'],
            'code' => $user instanceof PlatformUser && $user->twoFactorEnabled()
                ? ['required', 'string', 'max:64']
                : ['nullable', 'string', 'max:64'],
        ];
    }
}
