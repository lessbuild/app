<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use Carbon\CarbonImmutable;

final readonly class BrowserSession
{
    public function __construct(
        public string $id,
        public string $device,
        public ?string $ipAddress,
        public CarbonImmutable $lastActiveAt,
        public bool $current,
    ) {}
}
