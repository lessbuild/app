<?php

namespace App\Core\Services\Auth;

use App\Core\Data\Auth\PlatformSsoExchange;
use App\Core\Models\PlatformAuthSession;
use App\Core\Models\PlatformSsoTicket;
use App\Core\Models\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Mints short-lived, single-use host-bound codes for the host-only SSO handoff. */
final class PlatformSsoTickets
{
    private const LIFETIME_SECONDS = 90;

    public function issue(
        PlatformUser $user,
        string $authSessionId,
        string $issuerOrigin,
        string $audienceOrigin,
        string $returnUrl,
    ): string {
        $authSession = PlatformAuthSession::query()
            ->whereKey($authSessionId)
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->first();

        abort_unless($authSession !== null, 401);

        PlatformSsoTicket::query()->where('expires_at', '<=', now())->delete();

        $plainTextTicket = bin2hex(random_bytes(32));

        PlatformSsoTicket::query()->create([
            'id' => (string) Str::ulid(),
            'token_hash' => hash('sha256', $plainTextTicket),
            'auth_session_id' => $authSession->getKey(),
            'user_id' => $user->getKey(),
            'issuer_origin' => $issuerOrigin,
            'audience_origin' => $audienceOrigin,
            'return_url' => $returnUrl,
            'expires_at' => now()->addSeconds(self::LIFETIME_SECONDS),
        ]);

        return $plainTextTicket;
    }

    public function consume(string $plainTextTicket, string $issuerOrigin, string $audienceOrigin): ?PlatformSsoExchange
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $plainTextTicket)) {
            return null;
        }

        return DB::connection('core')->transaction(function () use ($plainTextTicket, $issuerOrigin, $audienceOrigin): ?PlatformSsoExchange {
            $ticket = PlatformSsoTicket::query()
                ->where('token_hash', hash('sha256', $plainTextTicket))
                ->lockForUpdate()
                ->first();

            if ($ticket === null
                || $ticket->consumed_at !== null
                || $ticket->expires_at === null
                || $ticket->expires_at->isPast()
                || ! hash_equals((string) $ticket->issuer_origin, $issuerOrigin)
                || ! hash_equals((string) $ticket->audience_origin, $audienceOrigin)) {
                return null;
            }

            $authSession = PlatformAuthSession::query()
                ->whereKey($ticket->auth_session_id)
                ->where('user_id', $ticket->user_id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            $user = PlatformUser::query()
                ->whereKey($ticket->user_id)
                ->where('status', 'active')
                ->first();

            if ($authSession === null || $user === null) {
                return null;
            }

            $ticket->forceFill(['consumed_at' => now()])->save();

            return new PlatformSsoExchange($user, $authSession, (string) $ticket->return_url);
        }, attempts: 3);
    }
}
