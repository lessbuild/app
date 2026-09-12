<?php

namespace App\Http\Requests;

use App\Data\AccountDeletionData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteAccountRequest extends FormRequest
{
    /** Keep account-deletion validation failures in the existing named session bag. */
    protected $errorBag = 'deleteAccount';

    /** Account deletion is available only to the authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Require the existing confirmation and applicable authentication challenges before deletion.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();
        $rules = [
            'confirmation' => ['required', Rule::in([$user->email])],
        ];

        if ($user->hasLocalPassword()) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        if ($user->twoFactorEnabled()) {
            $rules['code'] = ['required', 'string', 'max:64'];
        }

        return $rules;
    }

    /** Build the explicit security challenge boundary consumed by the deletion action. */
    public function deletionData(): AccountDeletionData
    {
        $validated = $this->validated();

        return new AccountDeletionData(
            twoFactorCode: $validated['code'] ?? null,
        );
    }
}
