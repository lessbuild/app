<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Webhooks\DeleteWebhookEndpoint;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteWebhookEndpointController
{
    /**
     * Remove an endpoint.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  WebhookEndpoint  $endpoint
     * @param  DeleteWebhookEndpoint  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, WebhookEndpoint $endpoint, DeleteWebhookEndpoint $delete): RedirectResponse
    {
        $delete->handle($user, $account, $endpoint);

        return to_route('account.webhooks')->with('status', __('Endpoint removed.'));
    }
}
