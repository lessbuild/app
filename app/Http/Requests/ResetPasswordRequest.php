<?php

namespace App\Http\Requests;

use App\Data\ResetPasswordData;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    /** Password reset submissions are public and are checked by the password broker. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the token, account email and confirmed replacement password.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', 'min:8'],
        ];
    }

    /** Build the explicit reset credentials consumed by the password operation. */
    public function resetData(): ResetPasswordData
    {
        /** @var array{token: string, email: string, password: string} $validated */
        $validated = $this->validated();

        return new ResetPasswordData(
            token: $validated['token'],
            email: $validated['email'],
            password: $validated['password'],
        );
    }
}
