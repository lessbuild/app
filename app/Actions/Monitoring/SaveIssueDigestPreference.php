<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\Account;
use App\Models\IssueDigestPreference;
use App\Models\User;

final class SaveIssueDigestPreference
{
    /** Turn the daily issue digest on or off for someone in an account they belong to. */
    public function handle(Account $account, User $user, bool $enabled): void
    {
        abort_if($account->roleOf($user) === null, 403);
        $preference = IssueDigestPreference::query()->where('account_id', $account->id)->where('user_id', $user->id)->first() ?? new IssueDigestPreference;
        $preference->forceFill(['account_id' => $account->id, 'user_id' => $user->id, 'enabled' => $enabled])->save();
    }
}
