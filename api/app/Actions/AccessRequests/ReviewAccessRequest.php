<?php

declare(strict_types=1);

namespace App\Actions\AccessRequests;

use App\Models\AccessRequest;
use App\Models\User;
use App\Notifications\AccessInvitation;
use App\Services\Admin\PlatformAdmins;
use App\Services\Users\Registration;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Validation\ValidationException;

final class ReviewAccessRequest
{
    /**
     * Create a new ReviewAccessRequest instance.
     *
     * Records admins' decisions on access requests.
     *
     * @param  Registration  $registration  Issues invitations.
     * @param  PlatformAdmins  $admins  Records the review in the admin trail.
     */
    public function __construct(private readonly Registration $registration, private readonly PlatformAdmins $admins) {}

    /**
     * Mark a request contacted, invited or declined, with notes. Inviting (or resending) emails a new one-time sign-up
     * link and voids the old one; any other status voids it too. Accepted requests are final: only signing up through
     * the invitation accepts one.
     *
     * @param  User  $admin
     * @param  AccessRequest  $request
     * @param  string  $status  contacted, invited or declined (or pending)
     * @param  string|null  $notes
     * @param  bool  $resend  send a new invitation to an already invited request
     * @return void
     */
    public function handle(User $admin, AccessRequest $request, string $status, ?string $notes, bool $resend = false): void
    {
        if ($request->accepted_at !== null) {
            throw ValidationException::withMessages(['status' => __('They’ve already signed up; the request is closed.')]);
        }
        if (! in_array($status, ['pending', 'contacted', 'invited', 'declined'], true)) {
            throw ValidationException::withMessages(['status' => __('Only signing up through an invitation accepts a request.')]);
        }
        $previous = $request->status;
        $request->forceFill(['status' => $status, 'review_notes' => $notes, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        if ($status === 'invited' && ($previous !== 'invited' || $resend)) {
            $token = $this->registration->issueInvitation($request);
            (new AnonymousNotifiable)->route('mail', $request->email)->notify(new AccessInvitation(route('register', ['invite' => $token])));
        } elseif ($status !== 'invited') {
            $request->forceFill(['invitation_token_hash' => null, 'invitation_expires_at' => null]);
        }
        $request->save();
        $this->admins->record($admin, 'access_request.reviewed', "Marked the access request from {$request->email} {$status}.");
    }
}
