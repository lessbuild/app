<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Events\SocialIdentityDisconnected;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\User;

final class DisconnectSocialIdentity
{
    /** Unlink a provider, as long as the user keeps at least one other way to sign in. Returns false when it wasn't linked. */
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
