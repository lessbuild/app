<?php

declare(strict_types=1);

namespace App\Data\Accounts;

use App\Enums\AccountRole;

final readonly class InviteMemberData
{
    public function __construct(
        public string $email,
        public AccountRole $role,
    ) {}
}
