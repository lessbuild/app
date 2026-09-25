<?php

namespace App\Core\Http\Requests;

use App\Core\Models\PlatformUser;
use Illuminate\Foundation\Http\FormRequest;

final class BeginPlatformTwoFactorRequest extends FormRequest
{
    protected $errorBag = 'twoFactor';

    public function authorize(): bool
    {
        return $this->user('platform') instanceof PlatformUser;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $user = $this->user('platform');

        return $user instanceof PlatformUser && $user->hasPassword()
            ? ['current_password' => ['required', 'current_password:platform']]
            : [];
    }
}
