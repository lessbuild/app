<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\SocialProvider;
use App\Events\Users\SocialIdentityDisconnected;
use App\Exceptions\IdentityRuleViolation;
use App\Models\User;

final class DisconnectSocialIdentity
{
    /**
     * Unlink a provider, as long as the user keeps at least one other way to sign in. Returns false when it wasn't linked.
     *
     * @param  User  $user
     * @param  SocialProvider  $provider
     * @return bool
     */
    public function handle(User $user, SocialProvider $provider): bool
    {
        $identity = $user->socialIdentities()->where('provider', $provider)->first();
        if ($identity === null) {
            return false;
        }

        $otherMethods = $user->password !== null
            || $user->hasPasskeysEnabled()
            || $user->socialIdentities()->whereKeyNot($identity->getKey())->exists();
        if (! $otherMethods) {
            throw IdentityRuleViolation::lastSignInMethod();
        }

        $identity->delete();
        SocialIdentityDisconnected::dispatch($user, $provider);

        return true;
    }
}
