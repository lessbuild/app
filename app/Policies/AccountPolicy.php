<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\User;

final class AccountPolicy
{
    /**
     * Opening the account at all: any member.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return bool
     */
    public function view(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ViewAccount);
    }

    /**
     * Renaming the account and changing its settings.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return bool
     */
    public function update(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ManageSettings);
    }

    /**
     * Inviting, removing and changing members.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return bool
     */
    public function manageMembers(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ManageMembers);
    }

    /**
     * Seeing the account's plans, usage and invoices.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return bool
     */
    public function viewBilling(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ViewBilling);
    }

    /**
     * Changing plans and payment details.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return bool
     */
    public function manageBilling(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ManageBilling);
    }

    /**
     * Creating and revoking the account's API tokens.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return bool
     */
    public function manageApiTokens(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ManageApiTokens);
    }

    /**
     * Reading the account's audit log.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return bool
     */
    public function viewAuditLog(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ViewAuditLog);
    }

    /**
     * Whether the service shows up for this person at all (their membership may be limited to some services).
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $service
     * @return bool
     */
    public function useService(User $user, Account $account, string $service): bool
    {
        $membership = $user->membershipIn($account);

        return $membership !== null && $membership->role->allows(AccountPermission::ViewProjects) && $membership->canUseService($service);
    }

    /**
     * Deleting the account and everything in it: owners only.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return bool
     */
    public function delete(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::DeleteAccount);
    }

    /**
     * Whether the person's role in the account includes the permission; non-members hold none.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  AccountPermission  $permission
     * @return bool
     */
    private function permits(User $user, Account $account, AccountPermission $permission): bool
    {
        return $account->roleOf($user)?->allows($permission) ?? false;
    }
}
