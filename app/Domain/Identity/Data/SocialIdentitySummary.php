<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Enums\SocialProvider;
use Carbon\CarbonImmutable;

final readonly class SocialIdentitySummary
{
    public function __construct(
        public SocialProvider $provider,
        public ?string $email,
        public ?CarbonImmutable $connectedAt,
    ) {}
}
