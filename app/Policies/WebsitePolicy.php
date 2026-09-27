<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Policies\Concerns\ChecksAccountRole;

/** Like servers: anyone who can use Infrastructure sees websites; owners and admins create, change and delete them. */
final class WebsitePolicy
{
    use ChecksAccountRole;

    public function view(User $user, Website $website): bool
    {
        return $this->allows($user, $website->account_id, AccountPermission::ViewProjects, 'infrastructure');
    }

    public function create(User $user, Account|Project $scope): bool
    {
        return $this->allows($user, $scope instanceof Project ? $scope->account_id : $scope->id, AccountPermission::ManageSettings);
    }

    public function update(User $user, Website $website): bool
    {
        return $this->allows($user, $website->account_id, AccountPermission::ManageSettings);
    }

    public function delete(User $user, Website $website): bool
    {
        return $this->update($user, $website);
    }
}
