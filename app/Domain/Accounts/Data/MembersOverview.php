<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Data;

use App\Domain\Accounts\Enums\AccountRole;

final readonly class MembersOverview
{
    /**
     * @param  list<MemberRow>  $members
     * @param  list<InvitationRow>  $invitations  empty unless the viewer manages members
     * @param  list<AccountRole>  $assignableRoles
     */
    public function __construct(
        public ?AccountRole $viewerRole,
        public bool $canManage,
        public array $members,
        public array $invitations,
        public array $assignableRoles,
    ) {}
}
