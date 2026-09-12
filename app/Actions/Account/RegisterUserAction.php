<?php

namespace App\Actions\Account;

use App\Data\RegistrationData;
use App\Models\AccessRequest;
use App\Models\User;
use App\Services\AccessInvitation;
use App\Services\PersonalOrganization;
use App\Services\RegistrationAccess;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Validation\ValidationException;

class RegisterUserAction
{
    public function __construct(
        private readonly RegistrationAccess $registration,
        private readonly AccessInvitation $invitations,
        private readonly PersonalOrganization $organizations,
        private readonly Hasher $hasher,
    ) {}

    /**
     * Create a user through the open-registration or invitation protocol and ensure workspace ownership.
     *
     * A null result means registration became unavailable or an invitation expired while being consumed.
     */
    public function handle(RegistrationData $data): ?User
    {
        $invitation = $this->invitations->find($data->invitationToken);
        if (! $this->registration->allowsNewUser() && ! $invitation) {
            return null;
        }

        if ($invitation && ! hash_equals($invitation->email, $data->email)) {
            throw ValidationException::withMessages([
                'email' => __('Use the email address that received this invitation.'),
            ]);
        }

        $createUser = fn (): User => User::create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => $this->hasher->make($data->password),
            'password_set_at' => now(),
        ]);

        $user = $invitation
            ? $this->invitations->consume($data->invitationToken, function (AccessRequest $lockedInvitation) use ($data, $createUser): ?User {
                return hash_equals($lockedInvitation->email, $data->email) ? $createUser() : null;
            })
            : $this->registration->synchronized(fn (): ?User => $this->registration->allowsNewUser() ? $createUser() : null);

        if (! $user) {
            return null;
        }

        $this->organizations->ensure($user);

        return $user;
    }
}
