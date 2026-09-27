<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use App\Services\Infrastructure\ServerPricing;
use Illuminate\Support\Facades\Gate;

final class RefreshServerCosts
{
    /**
     * Updates servers' monthly costs from their providers' price lists.
     *
     * @param  ServerPricing  $pricing  Looks up each server's price.
     */
    public function __construct(private readonly ServerPricing $pricing) {}

    /** Ask each cloud provider for its current prices now, rather than waiting for the daily `servers:sync-costs`. */
    public function handle(User $actor, Account $account): int
    {
        Gate::forUser($actor)->authorize('create', [Server::class, $account]);

        return $this->pricing->refresh(Server::query()->where('account_id', $account->id)->with('provider')->get());
    }
}
