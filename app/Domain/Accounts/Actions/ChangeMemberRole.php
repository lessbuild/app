<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Events\MemberRoleChanged;
use App\Domain\Accounts\Exceptions\AccountRuleViolation;
use App\Domain\Accounts\Models\Membership;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ChangeMemberRole
{
    public function handle(User $actor, Membership $membership, AccountRole $role): Membership
    {
        $account = $membership->account;
        Gate::forUser($actor)->authorize('manageMembers', $account);

        [$membership, $from] = DB::transaction(function () use ($actor, $membership, $role, $account): array {
            // Lock every membership row so two concurrent demotions can't both pass the last-owner check.
            $memberships = Membership::query()->whereBelongsTo($account)->lockForUpdate()->get();
            $locked = $memberships->firstWhere('id', $membership->id);
            $actorRole = $memberships->firstWhere('user_id', $actor->id)?->role;
            if ($locked === null || $actorRole === null) {
                throw AccountRuleViolation::cannotAssign();
            }
            $from = $locked->role;
            if (! $actorRole->canAssign($role) || ! $actorRole->canAssign($from)) {
                throw AccountRuleViolation::cannotAssign();
            }
            if ($from === AccountRole::Owner && $role !== AccountRole::Owner
                && $memberships->where('role', AccountRole::Owner)->count() === 1) {
                throw AccountRuleViolation::lastOwner();
            }

            $locked->role = $role;
            $locked->save();

            return [$locked, $from];
        });

        if ($from !== $role) {
            MemberRoleChanged::dispatch($membership, $from, $role, $actor);
        }

        return $membership;
    }
}
