<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Queries;

use App\Domain\Accounts\Data\InvitationRow;
use App\Domain\Accounts\Data\MemberRow;
use App\Domain\Accounts\Data\MembersOverview;
use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\AccountInvitation;
use App\Domain\Accounts\Models\Membership;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

final class MembersOverviewQuery
{
    public function handle(Account $account, User $viewer): MembersOverview
    {
        $viewerRole = $account->roleOf($viewer);
        $canManage = Gate::forUser($viewer)->allows('manageMembers', $account);
        $assignable = $canManage && $viewerRole !== null
            ? array_values(array_filter(AccountRole::cases(), $viewerRole->canAssign(...)))
            : [];

        $members = $account->memberships()->with('user')->get()
            ->sortBy([
                fn (Membership $a, Membership $b): int => array_search($a->role, AccountRole::cases(), true) <=> array_search($b->role, AccountRole::cases(), true),
                fn (Membership $a, Membership $b): int => strcasecmp($a->user->name, $b->user->name),
            ])
            ->map(fn (Membership $membership): MemberRow => new MemberRow(
                membershipId: $membership->id,
                name: $membership->user->name,
                email: $membership->user->email,
                role: $membership->role,
                joinedAt: $membership->created_at ? CarbonImmutable::instance($membership->created_at) : null,
                isYou: $membership->user_id === $viewer->id,
                manageable: $canManage && $membership->user_id !== $viewer->id && ($viewerRole?->canAssign($membership->role) ?? false),
            ));

        $invitations = $canManage
            ? $account->invitations()->pending()->with('invitedBy')->latest()->get()->map(fn (AccountInvitation $invitation): InvitationRow => new InvitationRow(
                id: $invitation->id,
                email: $invitation->email,
                role: $invitation->role,
                invitedBy: $invitation->invitedBy?->name,
                expiresAt: CarbonImmutable::instance($invitation->expires_at),
            ))
            : collect();

        return new MembersOverview($viewerRole, $canManage, array_values($members->all()), array_values($invitations->all()), $assignable);
    }
}
