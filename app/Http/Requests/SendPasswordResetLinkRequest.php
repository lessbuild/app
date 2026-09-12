<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendPasswordResetLinkRequest extends FormRequest
{
    /** Password-reset link requests are public and intentionally reveal no account state. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the public email input used by the password broker.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }

    /** Return the validated email for the password-reset operation. */
    public function email(): string
    {
        /** @var array{email: string} $validated */
        $validated = $this->validated();

        return $validated['email'];
    }
}
