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

    /**
     * Determine whether the user can see a website: account members who may view projects and use Infrastructure.
     *
     * @param  User  $user
     * @param  Website  $website
     * @return bool
     */
    public function view(User $user, Website $website): bool
    {
        return $this->allows($user, $website->account_id, AccountPermission::ViewProjects, 'infrastructure');
    }

    /**
     * Determine whether the user can add a website: people who manage the account's settings.
     *
     * @param  User  $user
     * @param  Account|Project  $scope
     * @return bool
     */
    public function create(User $user, Account|Project $scope): bool
    {
        return $this->allows($user, $this->accountIdOf($scope), AccountPermission::ManageSettings);
    }

    /**
     * Determine whether the user can change a website's settings, domains and environment variables: people who manage
     * the account's settings.
     *
     * @param  User  $user
     * @param  Website  $website
     * @return bool
     */
    public function update(User $user, Website $website): bool
    {
        return $this->allows($user, $website->account_id, AccountPermission::ManageSettings);
    }

    /**
     * Determine whether the user can remove a website, which the same people as update can.
     *
     * @param  User  $user
     * @param  Website  $website
     * @return bool
     */
    public function delete(User $user, Website $website): bool
    {
        return $this->update($user, $website);
    }

    /**
     * Determine whether the user can run, schedule and verify backups, which also needs managed backups on the Deploy
     * plan.
     *
     * @param  User  $user
     * @param  Website  $website
     * @return Response
     */
    public function backUp(User $user, Website $website): Response
    {
        if (! $this->update($user, $website)) {
            return Response::deny();
        }

        return app(Entitlements::class)->for($website->account)->has('deploy.backups')
            ? Response::allow()
            : Response::deny(__('Managed backups come with the Pro Deploy plan and above.'));
    }

    /**
     * Determine whether the user can inspect the database, add database users and copy databases, which also needs
     * managed resources on the Deploy plan.
     *
     * @param  User  $user
     * @param  Website  $website
     * @return Response
     */
    public function manageDatabase(User $user, Website $website): Response
    {
        if (! $this->update($user, $website)) {
            return Response::deny();
        }

        return app(Entitlements::class)->for($website->account)->has('deploy.resources')
            ? Response::allow()
            : Response::deny(__('Database tools come with the Pro Deploy plan and above.'));
    }

    /**
     * Determine whether the user can restore a backup. Restoring works on any plan, so backups taken before a
     * downgrade can still be used.
     *
     * @param  User  $user
     * @param  Website  $website
     * @return bool
     */
    public function restore(User $user, Website $website): bool
    {
        return $this->update($user, $website);
    }
}
