<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPasswordRequest extends FormRequest
{
    /** Password confirmation is available only to an authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate a local password while leaving social-only redirect handling to the controller.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        if ($this->user()?->hasLocalPassword() !== true) {
            return [];
        }

        return [
            'password' => ['required', 'string'],
        ];
    }

    /** Return the validated password for the confirmation operation. */
    public function password(): string
    {
        /** @var array{password: string} $validated */
        $validated = $this->validated();

        return $validated['password'];
    }
}
