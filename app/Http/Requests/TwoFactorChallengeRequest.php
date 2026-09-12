<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    /** Two-factor challenge submissions are public while their session is pending. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules for an authenticator or recovery code.
     *
     * @return array{code: list<string>}
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
        ];
    }

    /** Return the validated challenge code. */
    public function code(): string
    {
        return (string) $this->validated('code');
    }
}
