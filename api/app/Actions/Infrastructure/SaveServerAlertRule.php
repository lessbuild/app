<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\Server;
use App\Models\ServerAlertRule;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SaveServerAlertRule
{
    /**
     * Add an alert on a server metric, for one server or (with `$server` null) every server in the account.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  Server|null  $server
     * @param  array{name: string, metric: string, operator: string, threshold: float|int|string, consecutive_breaches: int|string, cooldown_minutes: int|string}  $data
     * @return ServerAlertRule
     */
    public function handle(Account $account, User $actor, ?Server $server, array $data): ServerAlertRule
    {
        Gate::forUser($actor)->authorize('create', [ServerAlertRule::class, $account]);
        if ($server !== null) {
            $server = Server::query()->where('account_id', $account->id)->findOrFail($server->id);
        }
        $rule = new ServerAlertRule;
        $rule->forceFill([
            'account_id' => $account->id, 'created_by' => $actor->id, 'server_id' => $server?->id, 'name' => trim($data['name']),
            'metric' => $data['metric'], 'operator' => $data['operator'], 'threshold' => (float) $data['threshold'],
            'consecutive_breaches' => (int) $data['consecutive_breaches'], 'cooldown_minutes' => (int) $data['cooldown_minutes'],
        ])->save();

        return $rule;
    }
}
