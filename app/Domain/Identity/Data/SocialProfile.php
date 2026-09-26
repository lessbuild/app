<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

/** What a sign-in provider tells us about the person. The email is only set when the provider has verified it. */
final readonly class SocialProfile
{
    public function __construct(
        public string $providerUserId,
        public ?string $verifiedEmail,
        public string $name,
    ) {}
}
