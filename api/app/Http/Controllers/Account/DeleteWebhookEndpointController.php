<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Webhooks\DeleteWebhookEndpoint;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteWebhookEndpointController
{
    /**
     * Remove an endpoint.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  WebhookEndpoint  $endpoint
     * @param  DeleteWebhookEndpoint  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, WebhookEndpoint $endpoint, DeleteWebhookEndpoint $delete): JsonResponse
    {
        $delete->handle($user, $account, $endpoint);

        return response()->json(['redirect' => route('account.webhooks', [], false), 'message' => __('Endpoint removed.')]);
    }
}
