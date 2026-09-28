<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\AccessRequest;
use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Decides who may sign up. Registration is open unless `platform.registration.open` is off; then it takes an access
 * invitation, or an invitation to join an account, except for the very first person.
 */
final class Registration
{
    /**
     * Determine whether anyone can sign up: it's open, or nobody has yet.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return (bool) config('platform.registration.open') || ! User::query()->exists();
    }

    /**
     * Determine whether this email may sign up now, with an access invitation token if they have one.
     *
     * @param  string  $email
     * @param  string|null  $invite
     * @return bool
     */
    public function allows(string $email, ?string $invite): bool
    {
        return $this->isOpen() || $this->invitation($invite) !== null
            || AccountInvitation::query()->pending()->where('email', mb_strtolower(trim($email)))->exists();
    }

    /**
     * Find the access request a still-valid invitation token belongs to.
     *
     * @param  string|null  $invite
     * @return AccessRequest|null
     */
    public function invitation(?string $invite): ?AccessRequest
    {
        if (! is_string($invite) || strlen($invite) !== 64) {
            return null;
        }
        $request = AccessRequest::query()->where('invitation_token_hash', hash('sha256', $invite))->first();

        return $request?->invitationIsValid() === true ? $request : null;
    }

    /**
     * Issue a new invitation for a request: a fresh one-time token (returned for the email; only its hash is kept),
     * valid for the configured number of days.
     *
     * @param  AccessRequest  $request
     * @return string
     */
    public function issueInvitation(AccessRequest $request): string
    {
        $token = Str::random(64);
        $request->forceFill([
            'status' => 'invited', 'invitation_token_hash' => hash('sha256', $token), 'invited_at' => now(),
            'invitation_expires_at' => now()->addDays((int) config('platform.registration.invitation_days')), 'accepted_at' => null,
        ])->save();

        return $token;
    }

    /**
     * Mark the request behind an invitation token accepted, once, when someone signs up with it. Call inside the
     * registration's transaction.
     *
     * @param  string|null  $invite
     * @return void
     */
    public function accept(?string $invite): void
    {
        if (! is_string($invite) || strlen($invite) !== 64) {
            return;
        }
        DB::table('access_requests')->where('invitation_token_hash', hash('sha256', $invite))->where('status', 'invited')->whereNull('accepted_at')
            ->update(['status' => 'accepted', 'accepted_at' => now(), 'invitation_token_hash' => null, 'updated_at' => now()]);
    }
}
