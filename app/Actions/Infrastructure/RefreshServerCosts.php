<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\Infrastructure\CloudBills;
use App\Services\Infrastructure\ServerPricing;
use Illuminate\Support\Facades\Gate;

final class RefreshServerCosts
{
    /**
     * Create a new RefreshServerCosts instance.
     *
     * Updates servers' monthly costs from their providers' price lists.
     *
     * @param  ServerPricing  $pricing  Looks up each server's price.
     * @param  CloudBills  $bills  Reads what the providers actually charged.
     */
    public function __construct(private readonly ServerPricing $pricing, private readonly CloudBills $bills) {}

    /**
     * Ask each cloud provider for its current prices now, and for what they've actually
     * charged, rather than waiting for the daily `servers:sync-costs` and `providers:sync-bills`.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @return int
     */
    public function handle(User $actor, Account $account): int
    {
        Gate::forUser($actor)->authorize('create', [Server::class, $account]);

        Provider::query()->where('account_id', $account->id)->whereIn('type', array_map(fn ($type): string => $type->value, CloudBills::SUPPORTED))
            ->each(fn (Provider $provider): int => $this->bills->refresh($provider));

        return $this->pricing->refresh(Server::query()->where('account_id', $account->id)->with('provider')->get());
    }
}
