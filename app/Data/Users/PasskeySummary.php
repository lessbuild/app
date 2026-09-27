<?php

declare(strict_types=1);

namespace App\Data\Users;

use Carbon\CarbonImmutable;

final readonly class PasskeySummary
{
    /**
     * One passkey on the security page.
     *
     * @param  int  $id  The passkey's ID, used to rename or delete it.
     * @param  string  $name  The name the person gave it.
     * @param  ?string  $authenticator  The authenticator's product name when its AAGUID is known (for example "iCloud
     *                                  Keychain").
     * @param  ?CarbonImmutable  $createdAt  When it was registered.
     * @param  ?CarbonImmutable  $lastUsedAt  When it last signed the person in.
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $authenticator,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $lastUsedAt,
    ) {}
}
