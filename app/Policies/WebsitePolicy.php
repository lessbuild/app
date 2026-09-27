<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Policies\Concerns\ChecksAccountRole;
use App\Services\Billing\Entitlements;
use Illuminate\Auth\Access\Response;

/** Like servers: anyone who can use Infrastructure sees websites (and their backups); owners and admins create, change, back up, restore and delete them. */
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

    /** Running and scheduling backups, and verifying them, needs managed backups on the Deploy plan. */
    public function backUp(User $user, Website $website): Response
    {
        if (! $this->update($user, $website)) {
            return Response::deny();
        }

        return app(Entitlements::class)->for($website->account)->has('deploy.backups')
            ? Response::allow()
            : Response::deny(__('Managed backups come with the Pro Deploy plan and above.'));
    }

    /** Restoring works on any plan, so backups taken before a downgrade can still be used. */
    public function restore(User $user, Website $website): bool
    {
        return $this->update($user, $website);
    }
}
