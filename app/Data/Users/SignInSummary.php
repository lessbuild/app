<?php

declare(strict_types=1);

namespace App\Data\Users;

use App\Enums\SignInMethod;
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
