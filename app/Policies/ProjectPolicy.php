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

    /** Creating a project in the account. */
    public function create(User $user, Account $account): bool
    {
        return $this->allows($user, $account->id, AccountPermission::ManageProjects);
    }

    /** Opening the project's overview, settings and activity. */
    public function view(User $user, Project $project): bool
    {
        return $this->allows($user, $project->account_id, AccountPermission::ViewProjects);
    }

    /** Changing the project's details, environments and domains. */
    public function update(User $user, Project $project): bool
    {
        return $this->allows($user, $project->account_id, AccountPermission::ManageProjects);
    }

    /** Deleting the project, which the same people who manage it may do. */
    public function delete(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /** See a service's pages inside the project. */
    public function useService(User $user, Project $project, string $service): bool
    {
        return $this->allows($user, $project->account_id, AccountPermission::ViewProjects, $service);
    }

    /** Turn a service on or off, or change its settings. */
    public function manageService(User $user, Project $project, string $service): bool
    {
        return $this->allows($user, $project->account_id, AccountPermission::ManageProjects, $service);
    }

    /**
     * Deploy configuration of the project (documents, reviews and their deploys). A separate ability because route
     * middleware can't pass the literal service name to manageService.
     */
    public function manageDeploy(User $user, Project $project): bool
    {
        return $this->manageService($user, $project, 'deploy');
    }
}
