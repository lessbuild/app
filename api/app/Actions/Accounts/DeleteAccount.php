<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Events\Accounts\AccountDeleted;
use App\Models\Account;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteAccount
{
    /**
     * Delete an account and everything that belongs to it (memberships, invitations, tokens, audit log).
     *
     * @param  User  $actor
     * @param  Account  $account
     * @return void
     */
    public function handle(User $actor, Account $account): void
    {
        Gate::forUser($actor)->authorize('delete', $account);

        $id = $account->id;
        $name = $account->name;
        DB::transaction(function () use ($account): void {
            // Anyone working in this account moves to another account they belong to, if they have one.
            $affected = User::query()->where('current_account_id', $account->id)->get();
            foreach ($affected as $member) {
                $member->forceFill([
                    'current_account_id' => Membership::query()->where('user_id', $member->id)->where('account_id', '!=', $account->id)->value('account_id'),
                ])->save();
            }
            $account->delete();
        });

        AccountDeleted::dispatch($id, $name, $actor);
    }
}
