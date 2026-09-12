<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmTwoFactorRequest extends FormRequest
{
    /** Keep two-factor validation failures in the existing named session bag. */
    protected $errorBag = 'twoFactor';

    /** Two-factor confirmation is available only to the authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate the bounded authenticator code; pending-secret verification belongs to the operation.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
        ];
    }

    /** Return the validated authenticator code to the application operation. */
    public function code(): string
    {
        /** @var array{code: string} $validated */
        $validated = $this->validated();

        return $validated['code'];
    }
}
