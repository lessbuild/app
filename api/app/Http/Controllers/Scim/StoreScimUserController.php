<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Actions\Accounts\ProvisionScimUser;
use App\Models\Account;
use App\Support\Identity\ScimResources;
use App\Support\Identity\ScimUserInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreScimUserController
{
    /**
     * Add someone the identity provider assigned.
     *
     * @param  Request  $request
     * @param  ProvisionScimUser  $provision
     * @return JsonResponse
     */
    public function __invoke(Request $request, ProvisionScimUser $provision): JsonResponse
    {
        /** @var Account $account */
        $account = $request->attributes->get('scim.account');
        $scimUser = $provision->handle($account, ScimUserInput::fromResource($request->json()->all()));

        return ScimResources::respond(ScimResources::user($scimUser->load('user')), 201);
    }
}
