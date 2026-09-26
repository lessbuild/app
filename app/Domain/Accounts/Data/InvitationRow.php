<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Data;

use App\Domain\Accounts\Enums\AccountRole;
use Carbon\CarbonImmutable;

final readonly class InvitationRow
{
    public function __construct(
        public string $id,
        public string $email,
        public AccountRole $role,
        public ?string $invitedBy,
        public CarbonImmutable $expiresAt,
    ) {}
}
