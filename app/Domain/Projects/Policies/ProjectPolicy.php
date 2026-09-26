<?php

declare(strict_types=1);

namespace App\Domain\Projects\Policies;

use App\Domain\Accounts\Enums\AccountPermission;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;

final class ProjectPolicy
{
    public function create(User $user, Account $account): bool
    {
        return $this->permits($this->membership($user, $account->id), AccountPermission::ManageProjects);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->permits($this->membership($user, $project->account_id), AccountPermission::ViewProjects);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->permits($this->membership($user, $project->account_id), AccountPermission::ManageProjects);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /** See a service's pages inside the project. */
    public function useService(User $user, Project $project, string $service): bool
    {
        $membership = $this->membership($user, $project->account_id);

        return $this->permits($membership, AccountPermission::ViewProjects) && ($membership?->canUseService($service) ?? false);
    }

    /** Turn a service on or off, or change its settings. */
    public function manageService(User $user, Project $project, string $service): bool
    {
        $membership = $this->membership($user, $project->account_id);

        return $this->permits($membership, AccountPermission::ManageProjects) && ($membership?->canUseService($service) ?? false);
    }

    private function membership(User $user, string $accountId): ?Membership
    {
        return Membership::query()->where('account_id', $accountId)->where('user_id', $user->id)->first();
    }

    private function permits(?Membership $membership, AccountPermission $permission): bool
    {
        return $membership?->role->allows($permission) ?? false;
    }
}
