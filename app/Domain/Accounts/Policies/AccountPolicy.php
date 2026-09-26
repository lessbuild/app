<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Policies;

use App\Domain\Accounts\Enums\AccountPermission;
use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;

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

    public function delete(User $user, Account $account): bool
    {
        return $this->permits($user, $account, AccountPermission::DeleteAccount);
    }

    private function permits(User $user, Account $account, AccountPermission $permission): bool
    {
        return $account->roleOf($user)?->allows($permission) ?? false;
    }
}
