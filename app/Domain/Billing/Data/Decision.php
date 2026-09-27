<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

final readonly class Decision
{
    public function __construct(
        public bool $allowed,
        public ?int $limit,
        public ?string $reason = null,
    ) {}
}
