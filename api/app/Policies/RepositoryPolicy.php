<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountRole;

/** Anyone with Deploy access sees repositories and their builds; members and above with Deploy access set them up and deploy. */
final class RepositoryPolicy
{
    use ChecksAccountRole;

    /**
     * Determine whether the user can see a repository and its deploys: account members who may view projects and use
     * Deploy.
     *
     * @param  User  $user
     * @param  Repository  $repository
     * @return bool
     */
    public function view(User $user, Repository $repository): bool
    {
        return $this->allows($user, $repository->project->account_id, AccountPermission::ViewProjects, 'deploy');
    }

    /**
     * Determine whether the user can connect a repository to a project: members who may manage projects and use
     * Deploy.
     *
     * @param  User  $user
     * @param  Project  $project
     * @return bool
     */
    public function create(User $user, Project $project): bool
    {
        return $this->allows($user, $project->account_id, AccountPermission::ManageProjects, 'deploy');
    }

    /**
     * Determine whether the user can change a repository's branch, commands and webhook: the same people as create.
     *
     * @param  User  $user
     * @param  Repository  $repository
     * @return bool
     */
    public function update(User $user, Repository $repository): bool
    {
        return $this->allowsInProject($user, $repository->project, AccountPermission::ManageProjects, 'deploy');
    }

    /**
     * Determine whether the user can disconnect a repository, which the same people as update can.
     *
     * @param  User  $user
     * @param  Repository  $repository
     * @return bool
     */
    public function delete(User $user, Repository $repository): bool
    {
        return $this->update($user, $repository);
    }

    /**
     * Determine whether the user can deploy, redeploy, roll back and cancel the repository's deploys: people who manage
     * it and, when its environment is protected, may deploy protected environments.
     *
     * @param  User  $user
     * @param  Repository  $repository
     * @return bool
     */
    public function deploy(User $user, Repository $repository): bool
    {
        return $this->update($user, $repository) && $this->mayTouchEnvironment($user, $repository->environment);
    }
}
