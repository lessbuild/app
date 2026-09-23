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
}
