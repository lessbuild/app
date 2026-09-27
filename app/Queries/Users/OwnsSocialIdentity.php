<?php

declare(strict_types=1);

namespace App\Queries\Users;

use App\Data\Users\SocialProfile;
use App\Enums\SocialProvider;
use App\Models\User;

final class OwnsSocialIdentity
{
    /**
     * Whether the provider account is already connected to this person.
     */
    public function handle(User $user, SocialProvider $provider, SocialProfile $profile): bool
    {
        return $user->socialIdentities()
            ->where('provider', $provider)
            ->where('provider_user_id', $profile->providerUserId)
            ->exists();
    }
}
