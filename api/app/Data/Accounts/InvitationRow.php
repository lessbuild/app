<?php

declare(strict_types=1);

namespace App\Data\Accounts;

use App\Enums\AccountRole;
use Carbon\CarbonImmutable;

final readonly class InvitationRow
{
    /**
     * Create a new InvitationRow instance.
     *
     * A pending invitation on the members page.
     *
     * @param  string  $id  The invitation's ID, used to revoke it.
     * @param  string  $email  Who it was sent to.
     * @param  AccountRole  $role  The role they'll get when they accept.
     * @param  ?string  $invitedBy  The name of whoever sent it, when they're still around.
     * @param  CarbonImmutable  $expiresAt  When the link stops working.
     */
    public function __construct(
        public string $id,
        public string $email,
        public AccountRole $role,
        public ?string $invitedBy,
        public CarbonImmutable $expiresAt,
    ) {}
}
