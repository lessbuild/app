<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountRole;

/**
 * Projects and the services inside them. Seeing a project needs the account's view permission; changing it needs
 * the manage permission. Service abilities also respect a membership limited to some services.
 */
final class ProjectPolicy
{
    use ChecksAccountRole;

    /**
     * Determine whether the user can create a project in the account.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return bool
     */
    public function create(User $user, Account $account): bool
    {
        return $this->allows($user, $account->id, AccountPermission::ManageProjects);
    }

    /**
     * Determine whether the user can open the project's overview, settings and activity.
     *
     * @param  User  $user
     * @param  Project  $project
     * @return bool
     */
    public function view(User $user, Project $project): bool
    {
        return $this->allows($user, $project->account_id, AccountPermission::ViewProjects);
    }

    /**
     * Determine whether the user can change the project's details, environments and domains.
     *
     * @param  User  $user
     * @param  Project  $project
     * @return bool
     */
    public function update(User $user, Project $project): bool
    {
        return $this->allows($user, $project->account_id, AccountPermission::ManageProjects);
    }

    /**
     * Determine whether the user can delete the project, which the same people who manage it can.
     *
     * @param  User  $user
     * @param  Project  $project
     * @return bool
     */
    public function delete(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /**
     * Determine whether the person may see a service's pages inside the project.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $service
     * @return bool
     */
    public function useService(User $user, Project $project, string $service): bool
    {
        return $this->allows($user, $project->account_id, AccountPermission::ViewProjects, $service);
    }

    /**
     * Turn a service on or off, or change its settings.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $service
     * @return bool
     */
    public function manageService(User $user, Project $project, string $service): bool
    {
        return $this->allows($user, $project->account_id, AccountPermission::ManageProjects, $service);
    }

    /**
     * Deploy configuration of the project (documents, reviews and their deploys). A separate ability because route
     * middleware can't pass the literal service name to manageService.
     *
     * @param  User  $user
     * @param  Project  $project
     * @return bool
     */
    public function manageDeploy(User $user, Project $project): bool
    {
        return $this->manageService($user, $project, 'deploy');
    }
}
