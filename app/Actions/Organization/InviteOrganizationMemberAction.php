<?php

namespace App\Actions\Organization;

use App\Data\OrganizationInvitationResult;
use App\Exceptions\OrganizationInvitationOperationException;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\Entitlements;
use App\Services\PlanLimits;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Str;

class InviteOrganizationMemberAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly PlanLimits $limits,
        private readonly Dispatcher $notifications,
    ) {}

    /**
     * Create or replace a workspace invitation under the existing seat lock, then notify its recipient.
     *
     * @param  array{email: string, role: string}  $attributes  Validated and normalized invitation attributes.
     *
     * @throws OrganizationInvitationOperationException When the domain or existing-member rule rejects the invite.
     */
    public function handle(Organization $organization, User $actor, array $attributes): OrganizationInvitationResult
    {
        $this->entitlements->enforce($organization, 'teams');
        $email = Str::lower($attributes['email']);
        $allowedDomains = $organization->allowed_email_domains ?? [];
        if ($allowedDomains !== [] && ! in_array(Str::afterLast($email, '@'), $allowedDomains, true)) {
            throw new OrganizationInvitationOperationException('This email domain is not allowed by the workspace security policy.');
        }
        if ($organization->members()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw new OrganizationInvitationOperationException('This person is already a member.');
        }

        [$invitation, $token] = $this->limits->withinLimit($actor, 'members', function (Organization $lockedOrganization) use ($actor, $attributes, $email): array {
            $token = Str::random(64);
            $invitation = $lockedOrganization->invitations()->updateOrCreate(['email' => $email], [
                'invited_by' => $actor->id,
                'role' => $attributes['role'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
            ]);

            return [$invitation, $token];
        });

        $result = new OrganizationInvitationResult($invitation, $email, $token);
        $url = route('organizations.invitations.accept', ['invitation' => $result->invitation, 'token' => $result->token]);
        $this->notifications->send(
            (new AnonymousNotifiable)->route('mail', $result->email),
            new OrganizationInvitationNotification($organization->name, $url),
        );

        return $result;
    }
}
