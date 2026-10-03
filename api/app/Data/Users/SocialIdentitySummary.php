<?php

declare(strict_types=1);

namespace App\Data\Users;

use App\Enums\SocialProvider;
use Carbon\CarbonImmutable;

final readonly class SocialIdentitySummary
{
    /**
     * Create a new SocialIdentitySummary instance.
     *
     * A provider account connected to the signed-in person, for the security page.
     *
     * @param  SocialProvider  $provider  Which provider it is.
     * @param  ?string  $email  The email the provider reported when it was connected.
     * @param  ?CarbonImmutable  $connectedAt  When it was connected.
     */
    public function __construct(
        public SocialProvider $provider,
        public ?string $email,
        public ?CarbonImmutable $connectedAt,
    ) {}
}
