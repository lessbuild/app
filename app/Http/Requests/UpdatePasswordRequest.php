<?php

namespace App\Http\Requests;

use App\Data\PasswordUpdateData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    /** Keep password validation failures in the existing named session bag. */
    protected $errorBag = 'password';

    /** Password changes are available only to the authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate the conditional current-password challenge and confirmed replacement password.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $currentPasswordRules = $this->user()?->hasLocalPassword()
            ? ['required', 'current_password']
            : ['nullable'];

        return [
            'current_password' => $currentPasswordRules,
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /** Return only the validated replacement password to the application operation. */
    public function passwordData(): PasswordUpdateData
    {
        /** @var array{password: string} $validated */
        $validated = $this->validated();

        return new PasswordUpdateData($validated['password']);
    }
}
