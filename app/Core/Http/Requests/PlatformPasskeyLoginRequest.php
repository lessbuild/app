<?php

namespace App\Core\Http\Requests;

use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;

final class PlatformPasskeyLoginRequest extends PasskeyVerificationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'return_to' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
