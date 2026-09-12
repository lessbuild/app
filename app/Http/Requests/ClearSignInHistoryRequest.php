<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClearSignInHistoryRequest extends FormRequest
{
    /** Keep sign-in-history validation failures in the existing named session bag. */
    protected $errorBag = 'signIns';

    /** Sign-in history deletion is available only to the authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Require the current password before deleting account sign-in history.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
        ];
    }
}
