<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RevokeOtherSessionsRequest extends FormRequest
{
    /** Keep session-management validation failures in the existing named session bag. */
    protected $errorBag = 'sessions';

    /** Other-session revocation is available only to the authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Require the current local password before invalidating other sessions.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
        ];
    }

    /** Return the validated password challenge to the security operation. */
    public function currentPassword(): string
    {
        /** @var array{current_password: string} $validated */
        $validated = $this->validated();

        return $validated['current_password'];
    }
}
