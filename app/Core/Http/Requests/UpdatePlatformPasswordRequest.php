<?php

namespace App\Core\Http\Requests;

use App\Core\Models\PlatformUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class UpdatePlatformPasswordRequest extends FormRequest
{
    protected $errorBag = 'password';

    public function authorize(): bool
    {
        return $this->user('platform') instanceof PlatformUser;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $user = $this->user('platform');
        $hasPassword = $user instanceof PlatformUser && $user->hasPassword();
        $requiresCode = $user instanceof PlatformUser && ! $hasPassword && $user->twoFactorEnabled();

        return [
            'current_password' => $hasPassword
                ? ['required', 'current_password:platform']
                : ['nullable', 'string'],
            'code' => $requiresCode ? ['required', 'string', 'max:64'] : ['nullable', 'string', 'max:64'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
