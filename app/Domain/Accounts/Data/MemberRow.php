<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Data;

use App\Domain\Accounts\Enums\AccountRole;
use Carbon\CarbonImmutable;

final readonly class MemberRow
{
    public function __construct(
        public string $membershipId,
        public string $name,
        public string $email,
        public AccountRole $role,
        public ?CarbonImmutable $joinedAt,
        public bool $isYou,
        /** Whether the viewer may change this member's role or remove them. */
        public bool $manageable,
    ) {}
}
