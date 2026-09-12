<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnableTwoFactorRequest extends FormRequest
{
    /** Keep two-factor validation failures in the existing named session bag. */
    protected $errorBag = 'twoFactor';

    /** Two-factor setup is available only to the authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Preserve the existing already-enabled 422 before password validation is evaluated.
     */
    protected function prepareForValidation(): void
    {
        if ($this->user()?->twoFactorEnabled()) {
            abort(422);
        }
    }

    /**
     * Require a local password only when the account has configured one.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->user()?->hasLocalPassword()
            ? ['current_password' => ['required', 'current_password']]
            : [];
    }
}
