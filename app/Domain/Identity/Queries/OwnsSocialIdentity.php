<?php

declare(strict_types=1);

namespace App\Domain\Identity\Queries;

use App\Domain\Identity\Data\SocialProfile;
use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Models\User;

final class OwnsSocialIdentity
{
    public function handle(User $user, SocialProvider $provider, SocialProfile $profile): bool
    {
        return $user->socialIdentities()
            ->where('provider', $provider)
            ->where('provider_user_id', $profile->providerUserId)
            ->exists();
    }
}
