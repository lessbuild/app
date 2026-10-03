<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SetInfrastructureBudget
{
    /**
     * Set (or clear, with null) the monthly budget in USD that the costs page compares server costs with.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  float|null  $amount
     * @return void
     */
    public function handle(User $actor, Account $account, ?float $amount): void
    {
        Gate::forUser($actor)->authorize('manageCosts', [Server::class, $account]);
        $account->forceFill(['monthly_infrastructure_budget' => $amount === null ? null : round($amount, 2)])->save();
    }
}
