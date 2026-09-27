<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\AccountRole;
use App\Events\Accounts\AccountCreated;
use App\Models\Account;
use App\Models\Membership;
use App\Models\User;
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

    /**
     * A URL slug from the account's name, with a random suffix when it's taken. Slugs are global, so a random suffix
     * avoids guessable collisions.
     */
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
