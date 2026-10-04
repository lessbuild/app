<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Agency\DeleteClient;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteClientController
{
    /**
     * Remove a client.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  Client  $client
     * @param  DeleteClient  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, Client $client, DeleteClient $delete): JsonResponse
    {
        $delete->handle($user, $account, $client);

        return response()->json(['redirect' => route('account.clients', [], false), 'message' => __('Client removed.')]);
    }
}
