<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Provider;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountRole;

/** Providers are account credentials: only people who manage the account's settings see or change them. */
final class ProviderPolicy
{
    use ChecksAccountRole;

    /**
     * Determine whether the user can see the providers of their current account.
     *
     * @param  User  $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->current_account_id !== null && $this->allows($user, $user->current_account_id, AccountPermission::ManageSettings);
    }

    /**
     * Determine whether the user can connect a new provider to the current account, which the same people as viewAny
     * can.
     *
     * @param  User  $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can see a provider's details: the account's settings managers.
     *
     * @param  User  $user
     * @param  Provider  $provider
     * @return bool
     */
    public function view(User $user, Provider $provider): bool
    {
        return $this->allows($user, $provider->account_id, AccountPermission::ManageSettings);
    }

    /**
     * Determine whether the user can replace a provider's credential or rename it, which the same people as view can.
     *
     * @param  User  $user
     * @param  Provider  $provider
     * @return bool
     */
    public function update(User $user, Provider $provider): bool
    {
        return $this->view($user, $provider);
    }

    /**
     * Determine whether the user can disconnect a provider, which the same people as view can.
     *
     * @param  User  $user
     * @param  Provider  $provider
     * @return bool
     */
    public function delete(User $user, Provider $provider): bool
    {
        return $this->view($user, $provider);
    }
}
