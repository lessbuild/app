<?php

namespace App\Services;

use App\Models\AccessRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccessInvitation
{
    /**
     * Replace an applicant's invitation token and reset its acceptance window.
     *
     * @param  AccessRequest  $request  The access request to mark invited; only a token hash is persisted.
     * @return string The new 64-character plaintext token for the invitation URL.
     */
    public function issue(AccessRequest $request): string
    {
        $token = Str::random(64);
        $request->update([
            'status' => 'invited',
            'invitation_token_hash' => hash('sha256', $token),
            'invited_at' => now(),
            'invitation_expires_at' => now()->addDays((int) config('lessbuild.registration.invitation_days', 7)),
            'accepted_at' => null,
        ]);

        return $token;
    }

    /**
     * Resolve a currently valid invitation without consuming it.
     *
     * @param  string  $token  The unhashed 64-character invitation token.
     * @return AccessRequest|null The invited request, or null for an invalid, expired or already consumed token.
     */
    public function find(string $token): ?AccessRequest
    {
        if (strlen($token) !== 64) {
            return null;
        }

        $request = AccessRequest::query()->where('invitation_token_hash', hash('sha256', $token))->first();

        return $request?->invitationIsValid() ? $request : null;
    }

    /**
     * Run account creation under the invitation lock and mark acceptance only after it succeeds.
     *
     * @param  string  $token  The plaintext invitation token to claim.
     * @param  callable(AccessRequest): mixed  $callback  Work performed for the locked request; exceptions roll back acceptance.
     * @return mixed The callback result, or null when the invitation is unavailable.
     *
     * @throws \Throwable When the callback or database transaction fails.
     */
    public function consume(string $token, callable $callback): mixed
    {
        return DB::transaction(function () use ($token, $callback): mixed {
            $request = AccessRequest::query()->where('invitation_token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $request?->invitationIsValid()) {
                return null;
            }

            $result = $callback($request);
            $request->update(['status' => 'accepted', 'accepted_at' => now(), 'invitation_token_hash' => null]);

            return $result;
        });
    }
}
