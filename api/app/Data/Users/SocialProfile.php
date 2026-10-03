<?php

declare(strict_types=1);

namespace App\Data\Users;

/** What a sign-in provider tells us about the person. The email is only set when the provider has verified it. */
final readonly class SocialProfile
{
    /**
     * Create a new SocialProfile instance.
     *
     * What a sign-in provider told us about the person after they approved the connection.
     *
     * @param  string  $providerUserId  The provider's stable ID for the person, which identifies them on later sign-ins
     *                                  even if their email changes.
     * @param  ?string  $verifiedEmail  Their email, only when the provider says it's verified; we never trust an
     *                                  unverified one.
     * @param  string  $name  Their display name at the provider, used when registering them.
     */
    public function __construct(
        public string $providerUserId,
        public ?string $verifiedEmail,
        public string $name,
    ) {}
}
