<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Webhooks\SaveWebhookEndpoint;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateWebhookEndpointController
{
    /**
     * Change an endpoint's address, events or whether it's on.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  WebhookEndpoint  $endpoint
     * @param  SaveWebhookEndpoint  $save
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, WebhookEndpoint $endpoint, SaveWebhookEndpoint $save): RedirectResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2048'], 'description' => ['nullable', 'string', 'max:200'], 'events' => ['required', 'array', 'min:1'], 'events.*' => ['string', 'max:60'], 'enabled' => ['sometimes', 'boolean']]);
        $save->handle($user, $account, ['url' => $data['url'], 'description' => $data['description'] ?? null, 'events' => array_values($data['events']), 'enabled' => $request->boolean('enabled')], $endpoint);

        return to_route('account.webhooks')->with('status', __('Endpoint saved.'));
    }
}
