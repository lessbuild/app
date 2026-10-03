<?php

declare(strict_types=1);

namespace App\Actions\Agency;

use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteClient
{
    /**
     * Remove a client. Their projects stay.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  Client  $client
     * @return void
     */
    public function handle(User $actor, Account $account, Client $client): void
    {
        Gate::forUser($actor)->authorize('update', $account);
        abort_if($client->account_id !== $account->id, 404);
        $client->delete();
    }
}
