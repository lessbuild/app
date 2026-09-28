<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\Account;
use App\Models\IssueDigestPreference;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SaveIssueDigestPreference
{
    /**
     * Turn the daily issue digest on or off for someone in an account they belong to.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  bool  $enabled
     * @return void
     */
    public function handle(Account $account, User $user, bool $enabled): void
    {
        Gate::forUser($user)->authorize('view', $account);
        $preference = IssueDigestPreference::query()->where('account_id', $account->id)->where('user_id', $user->id)->first() ?? new IssueDigestPreference;
        $preference->forceFill(['account_id' => $account->id, 'user_id' => $user->id, 'enabled' => $enabled])->save();
    }
}
