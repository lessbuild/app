<?php

namespace App\Core\Http\Requests;

final class BeginPlatformPasskeyRegistrationRequest extends PlatformPasskeyActionRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            ...$this->reauthenticationRules(),
        ];
    }
}
