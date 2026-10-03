<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Exceptions\ScimException;
use App\Models\Account;
use App\Models\ScimUser;
use App\Support\Identity\ScimResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowScimUserController
{
    /**
     * Show one person the identity provider manages.
     *
     * @param  Request  $request
     * @param  string  $id
     * @return JsonResponse
     */
    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var Account $account */
        $account = $request->attributes->get('scim.account');
        $scimUser = ScimUser::query()->where('account_id', $account->id)->with('user')->find($id) ?? throw new ScimException(404, 'No such user.');

        return ScimResources::respond(ScimResources::user($scimUser));
    }
}
