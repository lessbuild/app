<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;

class ServerPolicy
{
    /**
     * Allow an account with deployment permission in its current workspace to provision a server.
     */
    public function create(User $user): bool
    {
        return $user->currentOrganization?->permits($user, 'deploy') ?? false;
    }

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

    /** Allow a workspace viewer to request a future short-lived troubleshooting connection. */
    public function connect(User $user, Server $server): bool
    {
        return $this->view($user, $server);
    }

    /** Require the stronger operations ability before a future session may accept shell input. */
    public function execute(User $user, Server $server): bool
    {
        return $this->view($user, $server)
            && ($server->organization?->permits($user, 'operate') ?? true);
    }

    /**
     * Allow any account that can view a server to request its fixed,
     * read-only diagnostic; arbitrary root commands remain an update ability.
     */
    public function diagnose(User $user, Server $server): bool
    {
        return $this->view($user, $server);
    }
}
