<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowAccountController
{
    /**
     * Return the account the token acts in (`GET /api/v1/account`).
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        $account = $request->attributes->get('account');
        abort_unless($account instanceof Account, 403);

        return response()->json(['data' => ['id' => $account->id, 'name' => $account->name, 'slug' => $account->slug]]);
    }
}
