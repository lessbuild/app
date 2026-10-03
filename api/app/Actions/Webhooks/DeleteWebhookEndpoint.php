<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Models\Account;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Gate;

final class DeleteWebhookEndpoint
{
    /**
     * Remove an endpoint and its delivery log.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  WebhookEndpoint  $endpoint
     * @return void
     */
    public function handle(User $actor, Account $account, WebhookEndpoint $endpoint): void
    {
        Gate::forUser($actor)->authorize('update', $account);
        abort_if($endpoint->account_id !== $account->id, 404);
        $endpoint->delete();
    }
}
