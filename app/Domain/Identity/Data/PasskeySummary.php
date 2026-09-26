<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use Carbon\CarbonImmutable;

final readonly class PasskeySummary
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $authenticator,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $lastUsedAt,
    ) {}
}
