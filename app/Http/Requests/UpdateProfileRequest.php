<?php

namespace App\Http\Requests;

use App\Data\ProfileUpdateData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /** Keep profile validation failures in the existing named session bag. */
    protected $errorBag = 'profile';

    /** Profile updates are available only to the authenticated account. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** Normalize the email before dynamic password rules and uniqueness validation are evaluated. */
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower((string) $this->input('email'))]);
    }

    /**
     * Validate the account name, unique normalized email and conditional current-password challenge.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $emailChanged = $this->user()?->email !== $this->input('email');
        $currentPasswordRules = $emailChanged && $this->user()?->hasLocalPassword()
            ? ['required', 'current_password']
            : ['exclude'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->id),
            ],
            'current_password' => $currentPasswordRules,
        ];
    }

    /** Return only validated profile values to the application operation. */
    public function profileData(): ProfileUpdateData
    {
        /** @var array{name: string, email: string, current_password?: string|null} $validated */
        $validated = $this->validated();

        return new ProfileUpdateData(
            name: $validated['name'],
            email: $validated['email'],
            currentPassword: $validated['current_password'] ?? null,
        );
    }
}
