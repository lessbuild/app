<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformAuthSession;
use App\Core\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Keeps a single Core login revocable across host-only browser sessions. */
final class PlatformAuthenticationSessions
{
    public function begin(PlatformUser $user, Request $request, bool $remember): PlatformAuthSession
    {
        $session = PlatformAuthSession::query()->create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->getKey(),
            'remember_token_hash' => $remember && filled($user->getRememberToken())
                ? hash('sha256', $user->getRememberToken())
                : null,
            'remembered' => $remember,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'last_seen_at' => now(),
        ]);

        $request->session()->put('platform.auth.session_id', $session->getKey());

        return $session;
    }

    public function ensure(PlatformUser $user, Request $request): ?PlatformAuthSession
    {
        $sessionId = $request->session()->get('platform.auth.session_id');

        if (is_string($sessionId)) {
            $session = PlatformAuthSession::query()
                ->whereKey($sessionId)
                ->where('user_id', $user->getKey())
                ->whereNull('revoked_at')
                ->first();

            if ($session !== null) {
                $this->touch($session);
            }

            return $session;
        }

        $guard = auth()->guard('platform');

        if ($guard->viaRemember()) {
            $rememberToken = $user->getRememberToken();

            if (! is_string($rememberToken) || $rememberToken === '') {
                return null;
            }

            $session = PlatformAuthSession::query()
                ->where('user_id', $user->getKey())
                ->where('remember_token_hash', hash('sha256', $rememberToken))
                ->where('remembered', true)
                ->whereNull('revoked_at')
                ->latest('created_at')
                ->first();

            if ($session === null) {
                return null;
            }

            $this->touch($session);
            $request->session()->put('platform.auth.session_id', $session->getKey());

            return $session;
        }

        return $this->begin($user, $request, false);
    }

    public function revoke(PlatformUser $user, Request $request): void
    {
        $sessionId = $request->session()->get('platform.auth.session_id');

        if (! is_string($sessionId)) {
            return;
        }

        PlatformAuthSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public function revokeAll(PlatformUser $user): void
    {
        PlatformAuthSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public function revokeOthers(PlatformUser $user, ?string $currentSessionId): bool
    {
        if (! is_string($currentSessionId) || $currentSessionId === '') {
            return false;
        }

        $currentSessionExists = PlatformAuthSession::query()
            ->whereKey($currentSessionId)
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->exists();

        if (! $currentSessionExists) {
            return false;
        }

        PlatformAuthSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->where('id', '!=', $currentSessionId)
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return true;
    }

    /** Return revoked, current, or inactive without allowing a caller to affect another account. */
    public function revokeOne(PlatformUser $user, string $sessionId, ?string $currentSessionId): string
    {
        if ($sessionId === $currentSessionId) {
            return 'current';
        }

        $affected = PlatformAuthSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return $affected > 0 ? 'revoked' : 'inactive';
    }

    private function touch(PlatformAuthSession $session): void
    {
        if ($session->last_seen_at !== null && $session->last_seen_at->gt(now()->subMinutes(5))) {
            return;
        }

        $lastSeenAt = now();
        PlatformAuthSession::query()
            ->whereKey($session->getKey())
            ->whereNull('revoked_at')
            ->update(['last_seen_at' => $lastSeenAt]);
        $session->setAttribute('last_seen_at', $lastSeenAt);
    }
}
