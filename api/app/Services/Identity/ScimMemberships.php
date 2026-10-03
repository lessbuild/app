<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AccountRole;
use App\Enums\AuditAction;
use App\Events\Accounts\MemberRemoved;
use App\Exceptions\ScimException;
use App\Models\Account;
use App\Models\Membership;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Webhooks\Webhooks;
use Illuminate\Support\Facades\DB;

/**
 * Adds and removes account members on an identity provider's behalf, with the same rules as people doing it: the
 * account's email domains and member limit, and never removing the last owner.
 */
final class ScimMemberships
{
    /**
     * Create a new ScimMemberships instance.
     *
     * @param  Entitlements  $entitlements  Checks the member limit.
     * @param  RecordAuditEntry  $audit  Records who was added.
     * @param  Webhooks  $webhooks  Announces new members.
     */
    public function __construct(private readonly Entitlements $entitlements, private readonly RecordAuditEntry $audit, private readonly Webhooks $webhooks) {}

    /**
     * Make the person a member with the account's SCIM role, unless they already are.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return void
     *
     * @throws ScimException
     */
    public function add(Account $account, User $user): void
    {
        if (! $account->allowsEmail($user->email)) {
            throw new ScimException(400, "{$account->name} doesn’t allow addresses at that domain.", 'invalidValue');
        }
        $added = DB::transaction(function () use ($account, $user): ?Membership {
            $memberships = Membership::query()->whereBelongsTo($account)->lockForUpdate()->get();
            if ($memberships->contains('user_id', $user->id)) {
                return null;
            }
            if (! $this->entitlements->for($account)->allows('account.members.max', $memberships->count() + 1)->allowed) {
                throw new ScimException(403, "{$account->name} has reached its plan’s member limit.");
            }
            $membership = new Membership;
            $membership->account()->associate($account);
            $membership->user()->associate($user);
            $membership->role = AccountRole::tryFrom($account->scim_default_role) ?? AccountRole::Member;
            $membership->save();
            if ($user->current_account_id === null) {
                $user->forceFill(['current_account_id' => $account->id])->save();
            }

            return $membership;
        });
        if ($added !== null) {
            $this->audit->handle(AuditAction::MemberProvisioned, null, $account->id, ['member' => $user->email, 'role' => $added->role->value]);
            $this->webhooks->dispatch($account->id, 'member.joined', ['member' => ['email' => $user->email, 'role' => $added->role->value], 'via' => 'scim']);
        }
    }

    /**
     * Remove the person's membership, if they have one.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return void
     *
     * @throws ScimException when they're the last owner
     */
    public function remove(Account $account, User $user): void
    {
        $role = DB::transaction(function () use ($account, $user): ?AccountRole {
            $memberships = Membership::query()->whereBelongsTo($account)->lockForUpdate()->get();
            $membership = $memberships->firstWhere('user_id', $user->id);
            if ($membership === null) {
                return null;
            }
            if ($membership->role === AccountRole::Owner && $memberships->where('role', AccountRole::Owner)->count() === 1) {
                throw new ScimException(409, 'The account’s last owner can’t be removed through SCIM.', 'mutability');
            }
            $membership->delete();
            if ($user->current_account_id === $account->id) {
                $user->forceFill(['current_account_id' => $user->memberships()->value('account_id')])->save();
            }

            return $membership->role;
        });
        if ($role !== null) {
            // The identity provider acted, so the record shows the person as leaving.
            MemberRemoved::dispatch($account, $user, $role, $user);
        }
    }
}
