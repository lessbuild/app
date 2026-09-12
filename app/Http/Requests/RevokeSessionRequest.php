<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevokeSessionRequest extends FormRequest
{
    /** Keep session-management validation failures in the existing named session bag. */
    protected $errorBag = 'sessions';

    /** Individual session revocation is available only to the authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Attest the submitted session ID against the route value and require the current password.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'session_id' => [
                'required',
                'string',
                'max:255',
                Rule::in([(string) $this->route('session')]),
            ],
            'current_password' => ['required', 'current_password'],
        ];
    }

    /** Return the route-attested, validated stored-session ID. */
    public function sessionId(): string
    {
        /** @var array{session_id: string} $validated */
        $validated = $this->validated();

        return $validated['session_id'];
    }
}
