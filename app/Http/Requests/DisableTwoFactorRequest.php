<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisableTwoFactorRequest extends FormRequest
{
    /** Keep two-factor validation failures in the existing named session bag. */
    protected $errorBag = 'twoFactor';

    /** Two-factor disablement is available only to the authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Require a local password when configured and always require an authenticator/recovery code.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:64'],
        ];

        if ($this->user()?->hasLocalPassword()) {
            $rules = [
                'current_password' => ['required', 'current_password'],
                ...$rules,
            ];
        }

        return $rules;
    }

    /** Return the validated authenticator or recovery code to the application operation. */
    public function code(): string
    {
        /** @var array{code: string} $validated */
        $validated = $this->validated();

        return $validated['code'];
    }
}
