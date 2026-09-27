<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\ServerAlertRule;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteServerAlertRule
{
    public function handle(Account $account, User $actor, ServerAlertRule $rule): void
    {
        Gate::forUser($actor)->authorize('update', $account);
        ServerAlertRule::query()->where('account_id', $account->id)->whereKey($rule->id)->delete();
    }
}
