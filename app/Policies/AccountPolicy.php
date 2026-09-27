<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\User;

final class AccountPolicy
{
    public function view(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ViewAccount);
    }

    public function update(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ManageSettings);
    }

    public function manageMembers(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ManageMembers);
    }

    public function viewBilling(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ViewBilling);
    }

    public function manageBilling(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ManageBilling);
    }

    public function manageApiTokens(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ManageApiTokens);
    }

    public function viewAuditLog(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::ViewAuditLog);
    }

    /** Whether the service shows up for this person at all (their membership may be limited to some services). */
    public function useService(User $user, Account $account, string $service): bool
    {
        $membership = $user->membershipIn($account);

        return $membership !== null && $membership->role->allows(AccountPermission::ViewProjects) && $membership->canUseService($service);
    }

    public function delete(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::DeleteAccount);
    }

    private function permits(User $user, Account $account, AccountPermission $permission): bool
    {
        return $account->roleOf($user)?->allows($permission) ?? false;
    }
}
