<?php

declare(strict_types=1);

namespace App\Data\Accounts;

use App\Enums\AccountRole;

final readonly class InviteMemberData
{
    /**
     * Create a new InviteMemberData instance.
     *
     * An invitation someone wants to send.
     *
     * @param  string  $email  Who to invite.
     * @param  AccountRole  $role  The role they'll get when they accept.
     */
    public function __construct(
        public string $email,
        public AccountRole $role,
    ) {}
}
