<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegenerateTwoFactorRecoveryCodesRequest extends FormRequest
{
    /** Keep two-factor validation failures in the existing named session bag. */
    protected $errorBag = 'twoFactor';

    /** Recovery-code regeneration is available only to the authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** Preserve the existing enabled-state 422 before password/code validation is evaluated. */
    protected function prepareForValidation(): void
    {
        if (! $this->user()?->twoFactorEnabled()) {
            abort(422);
        }
    }

    /**
     * Require a local password when configured and always require a bounded authenticator/recovery code.
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
