<?php

namespace App\Core\Http\Requests;

use App\Core\Models\PlatformUser;
use Illuminate\Foundation\Http\FormRequest;

abstract class PlatformPasskeyActionRequest extends FormRequest
{
    protected $errorBag = 'passkeys';

    public function authorize(): bool
    {
        $user = $this->user('platform');

        return $user instanceof PlatformUser && $user->status === 'active';
    }

    /** @return array<string, list<string>> */
    protected function reauthenticationRules(): array
    {
        $user = $this->user('platform');
        if (! $user instanceof PlatformUser) {
            return [];
        }

        $rules = [];
        if ($user->hasPassword()) {
            $rules['current_password'] = ['required', 'current_password:platform'];
        }
        if ($user->twoFactorEnabled()) {
            $rules['code'] = ['required', 'string', 'max:64'];
        }

        return $rules;
    }
}
