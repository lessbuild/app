<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Events\AccountCreated;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Membership;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateAccount
{
    /** Create an account owned by the user, and make it their current account if they have none. */
    public function handle(User $owner, string $name): Account
    {
        $account = DB::transaction(function () use ($owner, $name): Account {
            $account = Account::query()->create(['name' => $name, 'slug' => $this->uniqueSlug($name)]);

            $membership = new Membership;
            $membership->account()->associate($account);
            $membership->user()->associate($owner);
            $membership->role = AccountRole::Owner;
            $membership->save();

            if ($owner->current_account_id === null) {
                $owner->forceFill(['current_account_id' => $account->id])->save();
            }

            return $account;
        });

        AccountCreated::dispatch($account, $owner);

        return $account;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'account';
        $slug = $base;
        while (Account::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
