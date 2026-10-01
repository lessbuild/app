<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Actions\Accounts\DeprovisionScimUser;
use App\Exceptions\ScimException;
use App\Models\Account;
use App\Models\ScimUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DeleteScimUserController
{
    /**
     * Remove someone the identity provider unassigned.
     *
     * @param  Request  $request
     * @param  string  $id
     * @param  DeprovisionScimUser  $deprovision
     * @return Response
     */
    public function __invoke(Request $request, string $id, DeprovisionScimUser $deprovision): Response
    {
        /** @var Account $account */
        $account = $request->attributes->get('scim.account');
        $scimUser = ScimUser::query()->where('account_id', $account->id)->with('user')->find($id) ?? throw new ScimException(404, 'No such user.');
        $deprovision->handle($account, $scimUser);

        return response()->noContent();
    }
}
