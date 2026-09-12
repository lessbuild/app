<?php

namespace App\Http\Requests;

use App\Data\RegistrationData;
use App\Services\AccessInvitation;
use App\Services\RegistrationAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterUserRequest extends FormRequest
{
    /** Registration is a public flow whose availability is checked by the operation. */
    public function authorize(): bool
    {
        return true;
    }

    /** Normalize the email before applying the same lowercase validation as the existing controller. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower((string) $this->input('email')),
        ]);
    }

    /**
     * Validate registration fields only when open registration or a valid invitation permits the flow.
     *
     * Returning no rules for a closed flow preserves the existing registration-closed response before field
     * validation; the controller/action perform the same availability check again at the operation boundary.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        if (! $this->registrationIsAvailable()) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /** Build the validated account fields and protocol token consumed by registration. */
    public function registrationData(): RegistrationData
    {
        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $this->validated();

        return new RegistrationData(
            name: $validated['name'],
            email: $validated['email'],
            password: $validated['password'],
            invitationToken: $this->invitationToken(),
        );
    }

    /** Return the invitation token used by the access-invitation service; its protocol validation stays there. */
    public function invitationToken(): string
    {
        return (string) $this->input('invite');
    }

    /** Return whether this request has an open-registration or valid invitation path. */
    public function registrationIsAvailable(): bool
    {
        $registration = app(RegistrationAccess::class);
        if ($registration->allowsNewUser()) {
            return true;
        }

        return app(AccessInvitation::class)->find($this->invitationToken()) !== null;
    }
}
