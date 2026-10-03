<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        $name = fake()->company();

        return ['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6))];
    }

    public function withMember(User $user, AccountRole $role = AccountRole::Owner): static
    {
        return $this->afterCreating(function (Account $account) use ($user, $role): void {
            $membership = new Membership;
            $membership->account()->associate($account);
            $membership->user()->associate($user);
            $membership->role = $role;
            $membership->save();
            $user->forceFill(['current_account_id' => $user->current_account_id ?? $account->id])->save();
        });
    }
}
