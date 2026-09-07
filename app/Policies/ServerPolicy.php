<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    /**
     * Allow the server owner or a viewer in its currently selected organization.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Server  $server  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function view(User $user, Server $server): bool
    {
        return $server->organization
            ? (int) $server->organization_id === (int) $user->current_organization_id
                && $server->organization->permits($user, 'view')
            : (int) $server->user_id === (int) $user->id;
    }

    /**
     * Allow the server owner or an organization manager who can view it.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Server  $server  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function delete(User $user, Server $server): bool
    {
        return $this->view($user, $server)
            && ($server->organization?->permits($user, 'manage') ?? true);
    }

    /**
     * Allow the server owner or an organization deployer who can view it.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Server  $server  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function update(User $user, Server $server): bool
    {
        return $this->view($user, $server)
            && ($server->organization?->permits($user, 'deploy') ?? true);
    }
}
