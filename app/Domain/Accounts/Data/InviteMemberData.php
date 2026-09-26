<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Data;

use App\Domain\Accounts\Enums\AccountRole;

final readonly class InviteMemberData
{
    public function __construct(
        public string $email,
        public AccountRole $role,
    ) {}
}
