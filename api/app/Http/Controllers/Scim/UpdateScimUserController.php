<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Actions\Accounts\UpdateScimUser;
use App\Exceptions\ScimException;
use App\Models\Account;
use App\Models\ScimUser;
use App\Support\Identity\ScimResources;
use App\Support\Identity\ScimUserInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateScimUserController
{
    /**
     * Apply a PUT (the whole resource) or PATCH (operations) from the identity provider.
     *
     * @param  Request  $request
     * @param  string  $id
     * @param  UpdateScimUser  $update
     * @return JsonResponse
     */
    public function __invoke(Request $request, string $id, UpdateScimUser $update): JsonResponse
    {
        /** @var Account $account */
        $account = $request->attributes->get('scim.account');
        $scimUser = ScimUser::query()->where('account_id', $account->id)->with('user')->find($id) ?? throw new ScimException(404, 'No such user.');
        $body = $request->json()->all();
        $changes = $request->isMethod('PATCH') ? ScimUserInput::fromPatch($body) : ScimUserInput::fromResource($body);

        return ScimResources::respond(ScimResources::user($update->handle($account, $scimUser, $changes)));
    }
}
