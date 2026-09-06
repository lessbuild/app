<?php

namespace App\Policies;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProviderPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @param  User  $user  The account requesting access.
     * @return bool Whether this policy grants the requested ability.
     */
    public function viewAny(User $user): bool
    {
        //
        return false;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  User  $user  The account requesting access.
     * @param  Provider  $provider  The selected provider connection.
     * @return bool Whether this policy grants the requested ability.
     */
    public function view(User $user, Provider $provider): bool
    {
        return $provider->organization
            ? (int) $provider->organization_id === (int) $user->current_organization_id
                && $provider->organization->permits($user, 'view')
            : (int) $provider->user_id === (int) $user->id;
    }

    /**
     * Determine whether the user can create models.
     *
     * @param  User  $user  The account requesting access.
     * @return bool Whether this policy grants the requested ability.
     */
    public function create(User $user): bool
    {
        //
        return false;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  User  $user  The account requesting access.
     * @param  Provider  $provider  The selected provider connection.
     * @return bool Whether this policy grants the requested ability.
     */
    public function update(User $user, Provider $provider): bool
    {
        return $this->view($user, $provider)
            && ($provider->organization?->permits($user, 'manage') ?? true);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  User  $user  The account requesting access.
     * @param  Provider  $provider  The selected provider connection.
     * @return bool Whether this policy grants the requested ability.
     */
    public function delete(User $user, Provider $provider): bool
    {
        return $this->update($user, $provider);
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param  User  $user  The account requesting access.
     * @param  Provider  $provider  The selected provider connection.
     * @return bool Whether this policy grants the requested ability.
     */
    public function restore(User $user, Provider $provider): bool
    {
        //
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param  User  $user  The account requesting access.
     * @param  Provider  $provider  The selected provider connection.
     * @return bool Whether this policy grants the requested ability.
     */
    public function forceDelete(User $user, Provider $provider): bool
    {
        //
        return false;
    }
}
