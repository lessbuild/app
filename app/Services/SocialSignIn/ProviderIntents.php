<?php

declare(strict_types=1);

namespace App\Services\SocialSignIn;

use App\Enums\SocialProvider;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Remembers, in the session, that this browser started connecting a provider or confirming identity with one,
 * so the shared OAuth callback only completes flows the signed-in person actually began.
 */
final class ProviderIntents
{
    public const CONNECT = 'connect';

    public const CONFIRM = 'confirm';

    private const KEY = 'social.intent';

    private const TTL_SECONDS = 600;

    /**
     * Remembers that this browser started connecting or confirming with a provider, for whom and when.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SocialProvider  $provider
     * @param  string  $type
     * @return void
     */
    public function start(Request $request, User $user, SocialProvider $provider, string $type): void
    {
        $request->session()->put(self::KEY, ['type' => $type, 'provider' => $provider->value, 'user_id' => $user->id, 'at' => now()->getTimestamp()]);
    }

    /**
     * Takes the remembered intent out of the session (so it can only be used once) and says whether it matches this
     * person and provider and is still fresh. Null when nothing was started.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SocialProvider  $provider
     * @return array{type: string, valid: bool}|null null when no flow was started; valid is false when it expired or doesn't match
     */
    public function pull(Request $request, User $user, SocialProvider $provider): ?array
    {
        $intent = $request->session()->pull(self::KEY);
        if (! is_array($intent)) {
            return null;
        }
        $type = in_array($intent['type'] ?? null, [self::CONNECT, self::CONFIRM], true) ? $intent['type'] : self::CONNECT;

        return ['type' => $type, 'valid' => ($intent['provider'] ?? null) === $provider->value
            && ($intent['user_id'] ?? null) === $user->id
            && is_int($intent['at'] ?? null)
            && $intent['at'] >= now()->getTimestamp() - self::TTL_SECONDS];
    }
}
