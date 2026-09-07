<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Website;

class WebsitePolicy
{
    /**
     * Allow the website owner or a viewer in its currently selected organization.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Website  $website  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function view(User $user, Website $website): bool
    {
        return $website->organization
            ? (int) $website->organization_id === (int) $user->current_organization_id
                && $website->organization->permits($user, 'view')
            : (int) $website->user_id === (int) $user->id;
    }

    /**
     * Allow the website owner or an organization deployer who can view it.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Website  $website  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function update(User $user, Website $website): bool
    {
        return $this->view($user, $website)
            && ($website->organization?->permits($user, 'deploy') ?? true);
    }

    /**
     * Allow the website owner or an organization manager who can view it.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Website  $website  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function delete(User $user, Website $website): bool
    {
        return $this->view($user, $website)
            && ($website->organization?->permits($user, 'manage') ?? true);
    }
}
