<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Webhooks\SaveWebhookEndpoint;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreWebhookEndpointController
{
    /**
     * Add a webhook endpoint and show its signing secret once.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  SaveWebhookEndpoint  $save
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, SaveWebhookEndpoint $save): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2048'], 'description' => ['nullable', 'string', 'max:200'], 'events' => ['required', 'array', 'min:1'], 'events.*' => ['string', 'max:60']]);
        [, $secret] = $save->handle($user, $account, ['url' => $data['url'], 'description' => $data['description'] ?? null, 'events' => array_values($data['events'])]);

        // The signing secret is returned this once, for the receiving end to check signatures with.
        return response()->json(['redirect' => route('account.webhooks', [], false), 'message' => __('Endpoint added. Events are sent to it as they happen.'), 'secret' => $secret], 201);
    }
}
