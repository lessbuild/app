<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Webhooks\SendWebhook;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SendWebhookController
{
    /**
     * Send a test ping to an endpoint, or send one of its deliveries again.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  WebhookEndpoint  $endpoint
     * @param  SendWebhook  $send
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, WebhookEndpoint $endpoint, SendWebhook $send): JsonResponse
    {
        $deliveryId = $request->string('delivery')->toString();
        $delivery = $deliveryId !== '' ? WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->findOrFail($deliveryId) : null;
        $send->handle($user, $account, $endpoint, $delivery);

        return response()->json(['redirect' => route('account.webhooks', [], false), 'message' => $delivery === null ? __('Test event sent. Its result appears below in a moment.') : __('Sending it again.')]);
    }
}
