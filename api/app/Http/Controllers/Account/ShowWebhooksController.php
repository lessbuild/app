<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\View\View;

final class ShowWebhooksController
{
    /**
     * Show the account's webhook endpoints with their latest deliveries.
     *
     * @param  Account  $account
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account): View
    {
        $endpoints = WebhookEndpoint::query()->where('account_id', $account->id)->orderBy('id')->get();
        $deliveries = WebhookDelivery::query()->whereIn('webhook_endpoint_id', $endpoints->modelKeys())->latest()->limit(200)->get()
            ->groupBy('webhook_endpoint_id')->map(fn ($group) => $group->take(15));

        return view('account.webhooks', ['account' => $account, 'endpoints' => $endpoints, 'deliveries' => $deliveries]);
    }
}
