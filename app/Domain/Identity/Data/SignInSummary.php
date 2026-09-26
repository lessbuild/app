<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Enums\SignInMethod;
use Carbon\CarbonImmutable;

final readonly class SignInSummary
{
    public function __construct(
        public bool $succeeded,
        public ?SignInMethod $method,
        public bool $twoFactor,
        public string $device,
        public ?string $ipAddress,
        public CarbonImmutable $at,
    ) {}
}
