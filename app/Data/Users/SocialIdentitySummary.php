<?php

declare(strict_types=1);

namespace App\Data\Users;

use App\Enums\SocialProvider;
use Carbon\CarbonImmutable;

final readonly class SocialIdentitySummary
{
    public function __construct(
        public SocialProvider $provider,
        public ?string $email,
        public ?CarbonImmutable $connectedAt,
    ) {}
}
