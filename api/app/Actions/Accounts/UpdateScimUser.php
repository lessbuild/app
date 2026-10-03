<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Exceptions\ScimException;
use App\Models\Account;
use App\Models\ScimUser;
use App\Services\Identity\ScimMemberships;

final class UpdateScimUser
{
    /**
     * Create a new UpdateScimUser instance.
     *
     * @param  ScimMemberships  $memberships  Adds or removes the membership as the person is activated or deactivated.
     */
    public function __construct(private readonly ScimMemberships $memberships) {}

    /**
     * Apply the identity provider's changes. Deactivating removes the membership and reactivating restores it with the
     * account's SCIM role. The person's name and email belong to them across accounts, so they're left alone.
     *
     * @param  Account  $account
     * @param  ScimUser  $scimUser
     * @param  array{email?: string, name?: string, external_id?: string|null, active?: bool}  $changes
     * @return ScimUser
     *
     * @throws ScimException
     */
    public function handle(Account $account, ScimUser $scimUser, array $changes): ScimUser
    {
        abort_if($scimUser->account_id !== $account->id, 404);
        if (array_key_exists('active', $changes) && $changes['active'] !== $scimUser->active) {
            $changes['active'] ? $this->memberships->add($account, $scimUser->user) : $this->memberships->remove($account, $scimUser->user);
            $scimUser->forceFill(['active' => $changes['active']]);
        }
        if (array_key_exists('external_id', $changes)) {
            $scimUser->forceFill(['external_id' => $changes['external_id']]);
        }
        $scimUser->save();

        return $scimUser;
    }
}
