<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\ServerAlertRule;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteServerAlertRule
{
    /**
     * Delete one of the account's server alert rules.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  ServerAlertRule  $rule
     * @return void
     */
    public function handle(Account $account, User $actor, ServerAlertRule $rule): void
    {
        Gate::forUser($actor)->authorize('delete', $rule);
        if ($rule->built_in) {
            throw ValidationException::withMessages(['rule' => __('This is a built-in rule. Disable it instead of deleting it.')]);
        }
        ServerAlertRule::query()->where('account_id', $account->id)->whereKey($rule->id)->delete();
    }
}
