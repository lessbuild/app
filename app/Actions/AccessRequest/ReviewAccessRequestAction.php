<?php

namespace App\Actions\AccessRequest;

use App\Exceptions\AccessRequestReviewException;
use App\Models\AccessRequest;
use App\Models\User;
use App\Notifications\AccessInvitationNotification;
use App\Services\AccessInvitation;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\AnonymousNotifiable;

class ReviewAccessRequestAction
{
    public function __construct(
        private readonly AccessInvitation $invitations,
        private readonly Dispatcher $notifications,
    ) {}

    /**
     * Record a platform review and manage invitation credentials without changing accepted onboarding records.
     *
     * @param  AccessRequest  $accessRequest  Applicant record being reviewed.
     * @param  User  $reviewer  Platform administrator recorded as the reviewer.
     * @param  array{status: string, review_notes: ?string, resend_invitation: bool}  $attributes  Validated review input.
     *
     * @throws AccessRequestReviewException When an accepted record is changed or an unaccepted record is marked accepted.
     */
    public function handle(AccessRequest $accessRequest, User $reviewer, array $attributes): void
    {
        if ($accessRequest->accepted_at !== null && $attributes['status'] !== 'accepted') {
            throw new AccessRequestReviewException(__('Accepted requests are immutable onboarding records until retention removes them.'));
        }
        if ($accessRequest->accepted_at === null && $attributes['status'] === 'accepted') {
            throw new AccessRequestReviewException(__('Only successful invitation registration can accept a request.'));
        }

        $previousStatus = $accessRequest->status;
        $accessRequest->update([
            'status' => $attributes['status'],
            'review_notes' => $attributes['review_notes'] ?? null,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        if ($attributes['status'] === 'invited' && ($previousStatus !== 'invited' || $attributes['resend_invitation'])) {
            $token = $this->invitations->issue($accessRequest);
            $this->notifications->send(
                (new AnonymousNotifiable)->route('mail', $accessRequest->email),
                new AccessInvitationNotification(
                    route('register', ['invite' => $token]),
                    (int) config('lessbuild.registration.invitation_days', 7),
                ),
            );
        } elseif ($attributes['status'] !== 'invited' && $accessRequest->invitation_token_hash !== null) {
            $accessRequest->update(['invitation_token_hash' => null, 'invitation_expires_at' => null]);
        }
    }
}
