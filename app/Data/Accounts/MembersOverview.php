<?php

declare(strict_types=1);

namespace App\Data\Accounts;

use App\Enums\AccountRole;

final readonly class MembersOverview
{
    /**
     * Create a new MembersOverview instance.
     *
     * Everything the members page shows.
     *
     * @param  ?AccountRole  $viewerRole  The viewer's own role in the account.
     * @param  bool  $canManage  Whether the viewer may invite, change and remove members.
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
