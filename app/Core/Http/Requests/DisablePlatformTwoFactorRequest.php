<?php

namespace App\Core\Http\Requests;

use App\Core\Models\PlatformUser;
use Illuminate\Foundation\Http\FormRequest;

final class DisablePlatformTwoFactorRequest extends FormRequest
{
    protected $errorBag = 'twoFactor';

    public function authorize(): bool
    {
        return $this->user('platform') instanceof PlatformUser;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = ['code' => ['required', 'string', 'max:64']];
        $user = $this->user('platform');

        if ($user instanceof PlatformUser && $user->hasPassword()) {
            $rules['current_password'] = ['required', 'current_password:platform'];
        }

        return $rules;
    }

    public function code(): string
    {
        /** @var array{code:string} $validated */
        $validated = $this->validated();

        return $validated['code'];
    }
}
