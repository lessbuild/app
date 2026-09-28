<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Data\Users\SocialProfile;
use App\Enums\SocialProvider;
use App\Events\Users\SocialIdentityConnected;
use App\Exceptions\IdentityRuleViolation;
use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

final class ConnectSocialIdentity
{
    /**
     * Link a provider account to a signed-in user so they can sign in with it. Returns false when it was already linked.
     *
     * @param  User  $user
     * @param  SocialProvider  $provider
     * @param  SocialProfile  $profile
     * @return bool
     */
    public function handle(User $user, SocialProvider $provider, SocialProfile $profile): bool
    {
        if ($profile->verifiedEmail === null) {
            throw IdentityRuleViolation::noVerifiedEmail($provider->label());
        }

        $existing = SocialIdentity::query()->where('provider', $provider)->where('provider_user_id', $profile->providerUserId)->first();
        if ($existing !== null) {
            return $existing->user_id === $user->id ? false : throw IdentityRuleViolation::identityTaken();
        }
        if ($user->socialIdentities()->where('provider', $provider)->exists()) {
            throw IdentityRuleViolation::providerAlreadyConnected($provider->label());
        }

        $identity = new SocialIdentity;
        try {
            $identity->forceFill([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $profile->providerUserId,
                'email' => $profile->verifiedEmail,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Another request linked it between our check and the insert.
            throw IdentityRuleViolation::identityTaken();
        }

        SocialIdentityConnected::dispatch($user, $provider);

        return true;
    }
}
