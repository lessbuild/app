<?php

declare(strict_types=1);

namespace App\Data\Users;

use App\Enums\SocialSignInOutcome;
use App\Models\User;

final readonly class SocialSignInResult
{
    public function __construct(
        public SocialSignInOutcome $outcome,
        public ?User $user = null,
    ) {}
}
