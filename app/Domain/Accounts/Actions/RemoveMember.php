<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Events\MemberRemoved;
use App\Domain\Accounts\Exceptions\AccountRuleViolation;
use App\Domain\Accounts\Models\Membership;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RemoveMember
{
    /** Remove a member, or let a member leave; the account always keeps an owner. */
    public function handle(User $actor, Membership $membership): void
    {
        $account = $membership->account;
        $member = $membership->user;
        $leaving = $member->is($actor);
        if (! $leaving) {
            Gate::forUser($actor)->authorize('manageMembers', $account);
        }

        $role = DB::transaction(function () use ($actor, $membership, $account, $member, $leaving): AccountRole {
            $memberships = Membership::query()->whereBelongsTo($account)->lockForUpdate()->get();
            $locked = $memberships->firstWhere('id', $membership->id);
            if ($locked === null) {
                throw AccountRuleViolation::cannotAssign();
            }
            if (! $leaving && ! ($memberships->firstWhere('user_id', $actor->id)?->role->canAssign($locked->role) ?? false)) {
                throw AccountRuleViolation::cannotAssign();
            }
            if ($locked->role === AccountRole::Owner && $memberships->where('role', AccountRole::Owner)->count() === 1) {
                throw AccountRuleViolation::lastOwner();
            }

            $locked->delete();
            if ($member->current_account_id === $account->id) {
                $member->forceFill(['current_account_id' => $member->memberships()->value('account_id')])->save();
            }

            return $locked->role;
        });

        MemberRemoved::dispatch($account, $member, $role, $actor);
    }
}
