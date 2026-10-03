<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Exceptions\ScimException;
use App\Models\Account;
use App\Models\ScimUser;
use App\Services\Identity\ScimMemberships;

final class DeprovisionScimUser
{
    /**
     * Create a new DeprovisionScimUser instance.
     *
     * @param  ScimMemberships  $memberships  Removes the membership.
     */
    public function __construct(private readonly ScimMemberships $memberships) {}

    /**
     * Remove someone the identity provider unassigned: their membership goes, and the provider stops managing them.
     * Their user stays, since they may belong to other accounts.
     *
     * @param  Account  $account
     * @param  ScimUser  $scimUser
     * @return void
     *
     * @throws ScimException
     */
    public function handle(Account $account, ScimUser $scimUser): void
    {
        abort_if($scimUser->account_id !== $account->id, 404);
        $this->memberships->remove($account, $scimUser->user);
        $scimUser->delete();
    }
}
