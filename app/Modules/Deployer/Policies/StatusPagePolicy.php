<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\StatusPage;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;

class StatusPagePolicy
{
    /**
     * Allow a manager in the user's current workspace to create a status page.
     */
    public function create(User $user): bool
    {
        return $user->currentOrganization?->permits($user, 'manage') ?? false;
    }

    /**
     * Allow a manager to update only a status page in the selected workspace.
     */
    public function update(User $user, StatusPage $page): bool
    {
        return $this->manages($user, $page);
    }

    /**
     * Allow a manager to delete only a status page in the selected workspace.
     */
    public function delete(User $user, StatusPage $page): bool
    {
        return $this->manages($user, $page);
    }

    private function manages(User $user, StatusPage $page): bool
    {
        return (int) $page->organization_id === (int) $user->current_organization_id
            && $page->organization->permits($user, 'manage')
            && app(DeployerProjectAccess::class)->statusPages(StatusPage::query()->whereKey($page->getKey()), $user)->exists();
    }
}
