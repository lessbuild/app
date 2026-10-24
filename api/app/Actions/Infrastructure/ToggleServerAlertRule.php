<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\ServerAlertRule;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ToggleServerAlertRule
{
    /**
     * Enable or disable an alert rule (how a built-in rule is turned off, since it can't be deleted).
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  ServerAlertRule  $rule
     * @param  bool  $enabled
     * @return ServerAlertRule
     */
    public function handle(Account $account, User $actor, ServerAlertRule $rule, bool $enabled): ServerAlertRule
    {
        Gate::forUser($actor)->authorize('update', $rule);
        $rule->forceFill(['is_enabled' => $enabled, 'breach_count' => 0, 'is_alerting' => $enabled ? $rule->is_alerting : false])->save();

        return $rule;
    }
}
