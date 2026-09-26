<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Enums\SocialSignInOutcome;
use App\Domain\Identity\Models\User;

final readonly class SocialSignInResult
{
    public function __construct(
        public SocialSignInOutcome $outcome,
        public ?User $user = null,
    ) {}
}
