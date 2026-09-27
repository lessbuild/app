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

    /** Providers of the person's current account. */
    public function viewAny(User $user): bool
    {
        return $user->current_account_id !== null && $this->allows($user, $user->current_account_id, AccountPermission::ManageSettings);
    }

    /**
     * Connecting a new provider to the current account, allowed to the same people as viewAny.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Seeing a provider's details: the account's settings managers.
     */
    public function view(User $user, Provider $provider): bool
    {
        return $this->allows($user, $provider->account_id, AccountPermission::ManageSettings);
    }

    /**
     * Replacing a provider's credential or renaming it, allowed to the same people as view.
     */
    public function update(User $user, Provider $provider): bool
    {
        return $this->view($user, $provider);
    }

    /**
     * Disconnecting a provider, allowed to the same people as view.
     */
    public function delete(User $user, Provider $provider): bool
    {
        return $this->view($user, $provider);
    }
}
